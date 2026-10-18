export const SCHEMA_VERSION = 1;

export type GatewayEventType =
    | 'session.connecting'
    | 'session.qr.updated'
    | 'session.connected'
    | 'session.reconnecting'
    | 'session.disconnected'
    | 'session.logged_out'
    | 'session.failed'
    | 'message.received'
    | 'message.status.updated'
    | 'message.failed'
    | 'contact.identified'
    | 'message.reaction';

/**
 * Normalized event envelope delivered to Laravel (docs/events.md). These names are
 * application conventions, not Baileys event names.
 */
export type GatewayEvent = {
    eventId: string;
    type: GatewayEventType;
    schemaVersion: number;
    sessionId: string;
    occurredAt: string;
    payload: Record<string, unknown>;
};

export type EventSink = {
    publish(
        type: GatewayEventType,
        sessionId: string,
        payload: Record<string, unknown>,
    ): GatewayEvent;
};
