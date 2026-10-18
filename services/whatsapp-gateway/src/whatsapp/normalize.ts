import {
    getContentType,
    isJidBroadcast,
    isJidGroup,
    isJidNewsletter,
    isJidStatusBroadcast,
    isPnUser,
    jidNormalizedUser,
    normalizeMessageContent,
    type WAMessage,
} from '@whiskeysockets/baileys';
import type { IncomingMessage } from './adapter.ts';

export type NormalizedWithoutMedia = Omit<IncomingMessage, 'media'> & {
    mediaInfo: {
        mimeType: string;
        fileName: string | null;
        size: number;
    } | null;
};

/**
 * Phone number for a PN JID ("15550100100@s.whatsapp.net" → "+15550100100"); null for
 * LIDs and other identifiers that do not encode a phone number.
 */
export function phoneNumberFromJid(
    jid: string | null | undefined,
): string | null {
    if (!jid || !isPnUser(jid)) {
        return null;
    }

    const user = jidNormalizedUser(jid).split('@')[0];

    return user && /^\d{6,15}$/.test(user) ? `+${user}` : null;
}

/**
 * Translates a Baileys message into the gateway's normalized shape, or null for traffic
 * the workspace does not handle (own messages, groups, broadcasts, status, newsletters,
 * protocol stubs).
 */
export function normalizeIncoming(
    message: WAMessage,
): NormalizedWithoutMedia | null {
    const key = message.key;
    const remoteJid = key.remoteJid ?? undefined;

    if (
        key.fromMe ||
        !key.id ||
        !remoteJid ||
        isJidGroup(remoteJid) ||
        isJidBroadcast(remoteJid) ||
        isJidStatusBroadcast(remoteJid) ||
        isJidNewsletter(remoteJid)
    ) {
        return null;
    }

    const content = normalizeMessageContent(message.message);
    const contentType = getContentType(content);

    if (
        !content ||
        !contentType ||
        contentType === 'protocolMessage' ||
        contentType === 'senderKeyDistributionMessage'
    ) {
        return null;
    }

    const pnJid = isPnUser(remoteJid)
        ? remoteJid
        : isPnUser(key.remoteJidAlt)
          ? key.remoteJidAlt
          : undefined;
    const identityJid = jidNormalizedUser(pnJid ?? remoteJid);

    const base = {
        externalId: key.id,
        from: {
            jid: identityJid,
            phoneNumber: phoneNumberFromJid(pnJid),
            pushName: message.pushName ?? null,
        },
        rawType: contentType,
        timestamp: new Date(
            Number(message.messageTimestamp ?? Date.now() / 1000) * 1000,
        ).toISOString(),
    };

    switch (contentType) {
        case 'conversation':
            return {
                ...base,
                type: 'text',
                body: content.conversation ?? null,
                mediaInfo: null,
            };
        case 'extendedTextMessage':
            return {
                ...base,
                type: 'text',
                body: content.extendedTextMessage?.text ?? null,
                mediaInfo: null,
            };
        case 'imageMessage': {
            const image = content.imageMessage;

            return {
                ...base,
                type: 'image',
                body: image?.caption ?? null,
                mediaInfo: {
                    mimeType: image?.mimetype ?? 'image/jpeg',
                    fileName: null,
                    size: Number(image?.fileLength ?? 0),
                },
            };
        }
        case 'documentMessage': {
            const document = content.documentMessage;

            return {
                ...base,
                type: 'document',
                body: document?.caption ?? null,
                mediaInfo: {
                    mimeType: document?.mimetype ?? 'application/octet-stream',
                    fileName: document?.fileName ?? null,
                    size: Number(document?.fileLength ?? 0),
                },
            };
        }
        case 'audioMessage': {
            const audio = content.audioMessage;

            return {
                ...base,
                type: 'audio',
                body: null,
                mediaInfo: {
                    mimeType: audio?.mimetype ?? 'audio/ogg',
                    fileName: null,
                    size: Number(audio?.fileLength ?? 0),
                },
            };
        }
        default:
            return {
                ...base,
                type: 'unsupported',
                body: null,
                mediaInfo: null,
            };
    }
}

/**
 * Baileys WebMessageInfo.Status → delivery status. PENDING (1) carries no new evidence.
 */
export function deliveryStatusFromProto(
    status: number | null | undefined,
): 'sent' | 'delivered' | 'read' | 'failed' | null {
    switch (status) {
        case 0:
            return 'failed';
        case 2:
            return 'sent';
        case 3:
            return 'delivered';
        case 4:
        case 5:
            return 'read';
        default:
            return null;
    }
}
