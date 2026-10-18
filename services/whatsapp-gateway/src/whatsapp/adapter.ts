import type { AuthStateHandle } from '../auth/auth-state-repository.ts';

export type IncomingMedia = {
    mimeType: string;
    fileName: string | null;
    size: number;
    download: () => Promise<Buffer>;
};

export type Person = {
    jid: string;
    lid: string | null;
    phoneNumber: string | null;
    pushName: string | null;
};

/**
 * Protocol-independent incoming message. `phoneNumber` is null when only a LID / opaque
 * identifier is known — callers must not derive phone numbers from `jid` themselves.
 */
export type IncomingMessage = {
    externalId: string;
    /** The chat: the person for direct chats, the group for group chats (pushName = subject). */
    from: Person;
    chatType: 'direct' | 'group';
    /** Who wrote the message inside a group; null for direct chats. */
    participant: Person | null;
    type: 'text' | 'image' | 'document' | 'audio' | 'unsupported';
    body: string | null;
    rawType: string | null;
    timestamp: string;
    media: IncomingMedia | null;
};

export type IncomingReaction = {
    externalId: string;
    targetExternalId: string;
    /** Empty string when the reaction was removed. */
    emoji: string;
    from: Person;
    chatType: 'direct' | 'group';
    participant: Person | null;
    timestamp: string;
};

export type DeliveryStatus = 'sent' | 'delivered' | 'read' | 'failed';

export type CloseReason =
    | { kind: 'logged_out'; message: string }
    | { kind: 'restart_required'; message: string }
    | { kind: 'connection_replaced'; message: string }
    | { kind: 'qr_timeout'; message: string }
    | { kind: 'fatal'; message: string }
    | { kind: 'transient'; message: string };

export type AccountInfo = {
    jid: string | null;
    phoneNumber: string | null;
    displayName: string | null;
};

/** A LID ↔ phone-number pairing learned from WhatsApp. */
export type ContactIdentity = { lid: string; jid: string; phoneNumber: string };

export type ConnectionHandlers = {
    onQr: (qr: string) => void;
    onOpen: (account: AccountInfo) => void;
    onClose: (reason: CloseReason) => void;
    onMessage: (message: IncomingMessage) => void;
    onStatus: (messageId: string, status: DeliveryStatus) => void;
    onIdentity: (identity: ContactIdentity) => void;
    onReaction: (reaction: IncomingReaction) => void;
};

export type OutgoingContent =
    | { type: 'text'; text: string }
    | {
          type: 'image' | 'document' | 'audio';
          data: Buffer;
          mimeType: string;
          fileName: string | null;
          caption: string | null;
      };

export type WhatsAppConnection = {
    send: (
        to: string,
        content: OutgoingContent,
    ) => Promise<{ messageId: string }>;
    /** Close the socket and keep credentials. */
    close: () => Promise<void>;
    /** Unlink the device on WhatsApp's side. */
    logout: () => Promise<void>;
    /**
     * The contact's profile picture (preview size), or null when they have none or their
     * privacy settings hide it from this account.
     */
    profilePicture: (
        jid: string,
    ) => Promise<{ data: Buffer; mimeType: string } | null>;
    /** Phone-number identities for LIDs already known locally (no network lookup). */
    resolveLids: (lids: string[]) => Promise<ContactIdentity[]>;
};

export type WhatsAppAdapter = {
    readonly name: 'baileys' | 'mock';
    connect: (
        sessionId: string,
        auth: AuthStateHandle,
        handlers: ConnectionHandlers,
    ) => Promise<WhatsAppConnection>;
};
