export type AutomationActionType =
    | 'assign'
    | 'add_tag'
    | 'notify'
    | 'suggest_reply'
    | 'auto_reply';

export type AutomationAction = {
    type: AutomationActionType;
    user_id?: number | null;
    tag_id?: number | null;
    text?: string | null;
};

export type AutomationRule = {
    id: number;
    name: string;
    is_enabled: boolean;
    is_dry_run: boolean;
    priority: number;
    max_runs_per_conversation_per_hour: number;
    conditions: {
        keywords?: string[];
        business_hours?: 'inside' | 'outside' | null;
        tag_ids?: number[];
    };
    actions: AutomationAction[];
    runs_last_day: number;
};

export type AutomationExecutionRow = {
    id: number;
    rule: string;
    status: 'executed' | 'dry_run' | 'rate_limited' | 'failed';
    actions_taken: string[];
    error: string | null;
    contact: string | null;
    conversation_id: string | null;
    created_at: string;
};
