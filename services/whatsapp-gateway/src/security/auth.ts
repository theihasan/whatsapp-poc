import { createHash, createHmac, timingSafeEqual } from 'node:crypto';

/**
 * Constant-time comparison of a bearer token (hashing first equalizes lengths).
 */
export function isValidBearer(
    header: string | undefined,
    expectedToken: string,
): boolean {
    if (header === undefined || !header.startsWith('Bearer ')) {
        return false;
    }

    const given = createHash('sha256').update(header.slice(7)).digest();
    const expected = createHash('sha256').update(expectedToken).digest();

    return timingSafeEqual(given, expected);
}

/**
 * Webhook signature: "sha256=" + HMAC_SHA256(secret, "{timestamp}.{rawBody}").
 */
export function signWebhook(
    secret: string,
    timestamp: number,
    rawBody: string,
): string {
    return `sha256=${createHmac('sha256', secret).update(`${timestamp}.${rawBody}`).digest('hex')}`;
}
