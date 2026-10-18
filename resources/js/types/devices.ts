export type DeviceStatus =
    | 'created'
    | 'connecting'
    | 'qr_ready'
    | 'connected'
    | 'reconnecting'
    | 'disconnected'
    | 'logged_out'
    | 'failed';

export type ProviderCapabilities = {
    usesQrPairing: boolean;
    supportsMedia: boolean;
    supportsAutoReply: boolean;
    supportsBulkMessaging: boolean;
    isSimulated: boolean;
};

export type Device = {
    id: string;
    name: string;
    provider: 'mock' | 'unofficial_web' | 'official_cloud';
    provider_label: string;
    provider_enabled: boolean;
    capabilities: ProviderCapabilities | null;
    status: DeviceStatus;
    phone_number: string | null;
    display_name: string | null;
    last_error: string | null;
    connected_at: string | null;
    status_changed_at: string | null;
    created_at: string | null;
};

export type ProviderOption = {
    value: Device['provider'];
    label: string;
};

export type DeviceConnectionEvent = {
    id: number;
    from_status: DeviceStatus | null;
    to_status: DeviceStatus;
    reason: string | null;
    occurred_at: string;
};
