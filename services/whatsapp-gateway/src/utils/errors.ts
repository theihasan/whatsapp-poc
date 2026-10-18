/**
 * Errors with a stable code and HTTP status for the internal API.
 */
export class GatewayError extends Error {
    readonly code: string;
    readonly statusCode: number;

    constructor(code: string, statusCode: number, message: string) {
        super(message);
        this.code = code;
        this.statusCode = statusCode;
    }
}

export const sessionNotFound = () =>
    new GatewayError('session_not_found', 404, 'Unknown session.');
export const sessionNotConnected = () =>
    new GatewayError(
        'session_not_connected',
        409,
        'The session is not connected; the message was not sent.',
    );
export const sendTimeout = () =>
    new GatewayError(
        'send_timeout',
        504,
        'WhatsApp did not confirm the send in time; the outcome is unknown.',
    );
export const sendRejected = (message: string) =>
    new GatewayError('send_rejected', 422, message);
export const shuttingDown = () =>
    new GatewayError('shutting_down', 503, 'The gateway is shutting down.');
