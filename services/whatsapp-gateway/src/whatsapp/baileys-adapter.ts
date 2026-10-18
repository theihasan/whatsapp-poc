import {
    Browsers,
    DisconnectReason,
    downloadMediaMessage,
    jidNormalizedUser,
    makeCacheableSignalKeyStore,
    makeWASocket,
    type WAMessage,
} from '@whiskeysockets/baileys';
import type { Logger } from 'pino';
import {
    isPaired,
    type AuthStateHandle,
} from '../auth/auth-state-repository.ts';
import type {
    CloseReason,
    ConnectionHandlers,
    ContactIdentity,
    OutgoingContent,
    Person,
    WhatsAppAdapter,
    WhatsAppConnection,
} from './adapter.ts';
import {
    deliveryStatusFromProto,
    normalizeIncoming,
    phoneNumberFromJid,
    withResolvedPhone,
} from './normalize.ts';

export const MAX_PROFILE_PICTURE_BYTES = 1024 * 1024;

/**
 * Downloads a WhatsApp CDN avatar, accepting only small JPEG/PNG images.
 */
export async function downloadProfilePicture(
    url: string,
    fetchImpl: typeof fetch = fetch,
): Promise<{ data: Buffer; mimeType: string } | null> {
    if (!/^https:\/\/[a-z0-9.-]+\.whatsapp\.net\//i.test(url)) {
        return null;
    }

    const response = await fetchImpl(url, {
        signal: AbortSignal.timeout(10_000),
    });

    if (!response.ok) {
        return null;
    }

    const data = Buffer.from(await response.arrayBuffer());
    const isJpeg = data[0] === 0xff && data[1] === 0xd8;
    const isPng = data
        .subarray(0, 4)
        .equals(Buffer.from([0x89, 0x50, 0x4e, 0x47]));

    if (
        data.length === 0 ||
        data.length > MAX_PROFILE_PICTURE_BYTES ||
        (!isJpeg && !isPng)
    ) {
        return null;
    }

    return { data, mimeType: isPng ? 'image/png' : 'image/jpeg' };
}

function toIdentity(lid: string, pn: string): ContactIdentity | null {
    const phoneNumber = phoneNumberFromJid(pn);

    return phoneNumber === null
        ? null
        : {
              lid: jidNormalizedUser(lid),
              jid: jidNormalizedUser(pn),
              phoneNumber,
          };
}

/**
 * Maps a Baileys close error to a lifecycle decision for the session manager.
 */
export function classifyClose(error: unknown, paired: boolean): CloseReason {
    const statusCode = (
        error as { output?: { statusCode?: number } } | undefined
    )?.output?.statusCode;
    const message =
        error instanceof Error ? error.message : 'Connection closed';

    switch (statusCode) {
        case DisconnectReason.loggedOut:
            return {
                kind: 'logged_out',
                message: 'The device was unlinked from the phone.',
            };
        case DisconnectReason.restartRequired:
            return {
                kind: 'restart_required',
                message: 'Restart required after pairing.',
            };
        case DisconnectReason.connectionReplaced:
            return {
                kind: 'connection_replaced',
                message: 'Another client opened this session.',
            };
        case DisconnectReason.forbidden:
            return {
                kind: 'fatal',
                message:
                    'WhatsApp refused the connection (403). The number may be restricted.',
            };
        case DisconnectReason.multideviceMismatch:
        case DisconnectReason.badSession:
            return {
                kind: 'fatal',
                message:
                    'The stored session is invalid. Revoke and link the device again.',
            };
        case DisconnectReason.timedOut:
            return paired
                ? { kind: 'transient', message }
                : {
                      kind: 'qr_timeout',
                      message: 'The QR code was not scanned in time.',
                  };
        default:
            return { kind: 'transient', message };
    }
}

/**
 * Unofficial WhatsApp Web transport via Baileys. All protocol details stay here.
 */
export class BaileysAdapter implements WhatsAppAdapter {
    readonly name = 'baileys' as const;
    private readonly logger: Logger;
    private readonly maxMediaBytes: number;

    constructor(logger: Logger, maxMediaBytes: number) {
        this.logger = logger;
        this.maxMediaBytes = maxMediaBytes;
    }

    async connect(
        sessionId: string,
        auth: AuthStateHandle,
        handlers: ConnectionHandlers,
    ): Promise<WhatsAppConnection> {
        const protocolLogger = this.logger.child(
            { module: 'baileys', sessionId },
            { level: 'warn' },
        );

        const socket = makeWASocket({
            auth: {
                creds: auth.state.creds,
                keys: makeCacheableSignalKeyStore(
                    auth.state.keys,
                    protocolLogger,
                ),
            },
            logger: protocolLogger,
            browser: Browsers.ubuntu('Chrome'),
            markOnlineOnConnect: false,
            syncFullHistory: false,
            generateHighQualityLinkPreview: false,
        });

        let closed = false;

        socket.ev.on('creds.update', () => {
            void auth
                .saveCreds()
                .catch((error: unknown) =>
                    this.logger.error(
                        { err: error, sessionId },
                        'failed to persist credentials',
                    ),
                );
        });

        socket.ev.on('connection.update', (update) => {
            if (update.qr) {
                handlers.onQr(update.qr);
            }

            if (update.connection === 'open') {
                const jid = socket.user?.id ?? null;

                handlers.onOpen({
                    jid,
                    phoneNumber: phoneNumberFromJid(jid),
                    displayName:
                        socket.user?.name ?? socket.user?.notify ?? null,
                });
            }

            if (update.connection === 'close' && !closed) {
                closed = true;
                handlers.onClose(
                    classifyClose(
                        update.lastDisconnect?.error,
                        isPaired(auth.state.creds),
                    ),
                );
            }
        });

        socket.ev.on('messages.upsert', ({ messages, type }) => {
            if (type !== 'notify') {
                return;
            }

            void (async () => {
                for (const message of messages) {
                    await this.#dispatchIncoming(
                        socket,
                        message,
                        handlers,
                    ).catch((error: unknown) =>
                        this.logger.error(
                            { err: error, sessionId },
                            'failed to dispatch incoming message',
                        ),
                    );
                }
            })();
        });

        socket.ev.on('groups.update', (updates) => {
            for (const update of updates) {
                if (update.id && update.subject) {
                    this.#groupSubjects.set(update.id, {
                        subject: update.subject,
                        expiresAt: Date.now() + 60 * 60 * 1000,
                    });
                }
            }
        });

        socket.ev.on('lid-mapping.update', ({ lid, pn }) => {
            const identity = toIdentity(lid, pn);

            if (identity !== null) {
                handlers.onIdentity(identity);
            }
        });

        socket.ev.on('messages.update', (updates) => {
            for (const { key, update } of updates) {
                const status = deliveryStatusFromProto(update.status);

                if (key.fromMe && key.id && status !== null) {
                    handlers.onStatus(key.id, status);
                }
            }
        });

        return {
            send: async (to: string, content: OutgoingContent) => {
                const sent = await socket.sendMessage(
                    to,
                    this.#toBaileysContent(content),
                );

                if (!sent?.key.id) {
                    throw new Error('WhatsApp did not return a message id.');
                }

                return { messageId: sent.key.id };
            },
            close: async () => {
                closed = true;
                await Promise.resolve(socket.end(undefined));
            },
            logout: async () => {
                closed = true;
                await socket.logout();
            },
            profilePicture: async (jid: string) => {
                let url: string | undefined;

                try {
                    url = await socket.profilePictureUrl(
                        jid,
                        'preview',
                        10_000,
                    );
                } catch (error) {
                    const status = (
                        error as { output?: { statusCode?: number } }
                    ).output?.statusCode;

                    if (status === 401 || status === 403 || status === 404) {
                        return null;
                    }

                    throw error;
                }

                return url === undefined ? null : downloadProfilePicture(url);
            },
            resolveLids: async (lids: string[]) => {
                const pairs =
                    (await socket.signalRepository.lidMapping.getPNsForLIDs(
                        lids,
                    )) ?? [];

                return pairs
                    .map(({ lid, pn }) => toIdentity(lid, pn))
                    .filter((identity) => identity !== null);
            },
        };
    }

    readonly #groupSubjects = new Map<
        string,
        { subject: string | null; expiresAt: number }
    >();

    /**
     * Group name, cached for an hour (one metadata query per group, not per message).
     */
    async #groupSubject(
        socket: ReturnType<typeof makeWASocket>,
        groupJid: string,
    ): Promise<string | null> {
        const cached = this.#groupSubjects.get(groupJid);

        if (cached !== undefined && cached.expiresAt > Date.now()) {
            return cached.subject;
        }

        let subject: string | null = null;

        try {
            subject = (await socket.groupMetadata(groupJid)).subject || null;
        } catch (error) {
            this.logger.warn(
                { err: error, groupJid },
                'group metadata unavailable',
            );
        }

        this.#groupSubjects.set(groupJid, {
            subject,
            expiresAt: Date.now() + 60 * 60 * 1000,
        });

        return subject;
    }

    async #dispatchIncoming(
        socket: ReturnType<typeof makeWASocket>,
        message: WAMessage,
        handlers: ConnectionHandlers,
    ): Promise<void> {
        let normalized = normalizeIncoming(message);

        if (normalized === null) {
            return;
        }

        const resolve = async (person: Person): Promise<Person> =>
            person.phoneNumber === null && person.lid !== null
                ? withResolvedPhone(
                      person,
                      await socket.signalRepository.lidMapping.getPNForLID(
                          person.lid,
                      ),
                  )
                : person;

        if (normalized.chatType === 'group') {
            normalized = {
                ...normalized,
                from: {
                    ...normalized.from,
                    pushName: await this.#groupSubject(
                        socket,
                        normalized.from.jid,
                    ),
                },
                participant:
                    normalized.participant === null
                        ? null
                        : await resolve(normalized.participant),
            };
        } else {
            normalized = {
                ...normalized,
                from: await resolve(normalized.from),
            };
        }

        const { mediaInfo, ...rest } = normalized;
        const maxBytes = this.maxMediaBytes;

        handlers.onMessage({
            ...rest,
            media:
                mediaInfo === null
                    ? null
                    : {
                          ...mediaInfo,
                          download: async () => {
                              if (mediaInfo.size > maxBytes) {
                                  throw new Error(
                                      'Media exceeds the configured size limit.',
                                  );
                              }

                              const buffer = await downloadMediaMessage(
                                  message,
                                  'buffer',
                                  {},
                                  {
                                      logger: this.logger,
                                      reuploadRequest:
                                          socket.updateMediaMessage,
                                  },
                              );

                              if (buffer.length > maxBytes) {
                                  throw new Error(
                                      'Media exceeds the configured size limit.',
                                  );
                              }

                              return buffer;
                          },
                      },
        });
    }

    #toBaileysContent(content: OutgoingContent) {
        switch (content.type) {
            case 'text':
                return { text: content.text };
            case 'image':
                return {
                    image: content.data,
                    mimetype: content.mimeType,
                    caption: content.caption ?? undefined,
                };
            case 'audio':
                return { audio: content.data, mimetype: content.mimeType };
            case 'document':
                return {
                    document: content.data,
                    mimetype: content.mimeType,
                    fileName: content.fileName ?? 'document',
                    caption: content.caption ?? undefined,
                };
        }
    }
}
