import type { DeviceStatus } from './devices';

export type MessageStatus =
    | 'queued'
    | 'processing'
    | 'gateway_accepted'
    | 'sent'
    | 'delivered'
    | 'read'
    | 'failed'
    | 'unknown'
    | 'received';

export type MessageAttachment = {
    id: number;
    name: string | null;
    mime_type: string;
    size: number;
    url: string;
};

export type Message = {
    id: string;
    conversation_id: string;
    direction: 'incoming' | 'outgoing';
    type: 'text' | 'image' | 'document' | 'audio' | 'unsupported';
    body: string | null;
    status: MessageStatus;
    failure_reason: string | null;
    is_automated: boolean;
    media_error: string | null;
    group_sender: { name: string; phone_number: string | null } | null;
    reactions: { emoji: string; name: string }[];
    sender_name?: string | null;
    sent_at: string | null;
    delivered_at: string | null;
    read_at: string | null;
    created_at: string | null;
    attachments: MessageAttachment[];
};

export type ConversationSummary = {
    id: string;
    whatsapp_account_id: string;
    status: 'open' | 'closed';
    unread_count: number;
    last_message_at: string | null;
    last_message_preview: string | null;
    contact: {
        id: string;
        name: string;
        phone_number: string | null;
        avatar_url: string | null;
    };
    assignee: { id: number; name: string } | null;
};

export type ContactTag = { id: number; name: string };

export type ContactConsent = {
    id: number;
    scope: string;
    source: string;
    evidence: string | null;
    consented_at: string;
    revoked_at: string | null;
};

export type ContactDetails = {
    id: string;
    name: string | null;
    display_name: string;
    is_group: boolean;
    phone_number: string | null;
    wa_jid: string | null;
    source: 'manual' | 'incoming_message';
    notes: string | null;
    avatar_url: string | null;
    last_activity_at: string | null;
    created_at: string | null;
    tags?: ContactTag[];
    consents?: ContactConsent[];
};

export type InboxAccount = {
    id: string;
    name: string;
    status: DeviceStatus;
    provider: string;
};

export type ActiveConversation = {
    conversation: ConversationSummary;
    suggested_reply: string | null;
    contact: ContactDetails;
    device: InboxAccount;
    messages: Message[];
    has_older: boolean;
};

export type InboxFilters = {
    account: string | null;
    filter: 'all' | 'unread' | 'open' | 'closed' | 'mine' | 'direct' | 'groups';
    search: string;
};

export type Agent = { id: number; name: string };
