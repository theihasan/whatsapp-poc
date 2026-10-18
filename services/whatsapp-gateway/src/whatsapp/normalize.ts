import {
    getContentType,
    isJidBroadcast,
    isJidGroup,
    isJidNewsletter,
    isJidStatusBroadcast,
    isLidUser,
    isPnUser,
    jidNormalizedUser,
    normalizeMessageContent,
    type WAMessage,
} from '@whiskeysockets/baileys';
import type { IncomingMessage, Person } from './adapter.ts';

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
 * A user identity from a primary JID and its alternate (PN ↔ LID) form.
 */
function person(
    jid: string,
    altJid: string | null | undefined,
    pushName: string | null | undefined,
): Person {
    const pnJid = isPnUser(jid)
        ? jid
        : isPnUser(altJid ?? undefined)
          ? altJid
          : undefined;
    const lid = isLidUser(jid)
        ? jid
        : isLidUser(altJid ?? undefined)
          ? altJid
          : undefined;

    return {
        jid: jidNormalizedUser(pnJid ?? jid),
        lid: lid ? jidNormalizedUser(lid) : null,
        phoneNumber: phoneNumberFromJid(pnJid),
        pushName: pushName ?? null,
    };
}

/**
 * Translates a Baileys message into the gateway's normalized shape, or null for traffic
 * the workspace does not handle (own messages, broadcasts, status, newsletters, protocol
 * stubs). Group chats are kept: `from` is the group, `participant` the sender.
 */
type ChatContext = {
    id: string;
    from: Person;
    chatType: 'direct' | 'group';
    participant: Person | null;
};

/**
 * Shared filtering and identity extraction for anything a contact sends.
 */
function chatContext(message: WAMessage): ChatContext | null {
    const key = message.key;
    const remoteJid = key.remoteJid ?? undefined;

    if (
        key.fromMe ||
        !key.id ||
        !remoteJid ||
        isJidBroadcast(remoteJid) ||
        isJidStatusBroadcast(remoteJid) ||
        isJidNewsletter(remoteJid)
    ) {
        return null;
    }

    const isGroup = isJidGroup(remoteJid) === true;
    const sender = isGroup
        ? key.participant
            ? person(key.participant, key.participantAlt, message.pushName)
            : null
        : person(remoteJid, key.remoteJidAlt, message.pushName);

    if (sender === null) {
        return null;
    }

    return {
        id: key.id,
        from: isGroup
            ? { jid: remoteJid, lid: null, phoneNumber: null, pushName: null }
            : sender,
        chatType: isGroup ? 'group' : 'direct',
        participant: isGroup ? sender : null,
    };
}

export type NormalizedReaction = {
    externalId: string;
    targetExternalId: string;
    /** Empty string when the reaction was removed. */
    emoji: string;
    from: Person;
    chatType: 'direct' | 'group';
    participant: Person | null;
    timestamp: string;
};

/**
 * A reaction to an earlier message, or null when the message is not a reaction.
 */
export function normalizeReaction(
    message: WAMessage,
): NormalizedReaction | null {
    const reaction = normalizeMessageContent(message.message)?.reactionMessage;
    const context = reaction ? chatContext(message) : null;

    if (!reaction?.key?.id || context === null) {
        return null;
    }

    return {
        externalId: context.id,
        targetExternalId: reaction.key.id,
        emoji: reaction.text ?? '',
        from: context.from,
        chatType: context.chatType,
        participant: context.participant,
        timestamp: new Date(
            Number(message.messageTimestamp ?? Date.now() / 1000) * 1000,
        ).toISOString(),
    };
}

export function normalizeIncoming(
    message: WAMessage,
): NormalizedWithoutMedia | null {
    const context = chatContext(message);

    if (context === null) {
        return null;
    }

    const content = normalizeMessageContent(message.message);
    const contentType = getContentType(content);

    if (
        !content ||
        !contentType ||
        contentType === 'protocolMessage' ||
        contentType === 'senderKeyDistributionMessage' ||
        contentType === 'reactionMessage'
    ) {
        return null;
    }

    const base = {
        externalId: context.id,
        from: context.from,
        chatType: context.chatType,
        participant: context.participant,
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
 * Completes a LID-only person with their phone-number JID when one is known.
 */
export function withResolvedPhone(
    person: Person,
    pnJid: string | null,
): Person {
    const phoneNumber = phoneNumberFromJid(pnJid);

    if (person.phoneNumber !== null || pnJid === null || phoneNumber === null) {
        return person;
    }

    return { ...person, jid: jidNormalizedUser(pnJid), phoneNumber };
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
