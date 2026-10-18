import type { ContactDetails } from './inbox';

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export type ContactListItem = ContactDetails & { has_consent: boolean };

export type ContactConversation = {
    id: string;
    device: string;
    status: 'open' | 'closed';
    last_message_at: string | null;
    last_message_preview: string | null;
};

export type ContactActivity = {
    messages_in: number;
    messages_out: number;
    events: {
        id: number;
        action: string;
        user: string | null;
        created_at: string;
    }[];
};
