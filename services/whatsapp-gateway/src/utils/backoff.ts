/**
 * Exponential backoff with "equal jitter": half the delay is fixed, half is random,
 * so retries spread out without ever collapsing to zero.
 */
export function backoffDelay(
    attempt: number,
    baseMs: number,
    maxMs: number,
    random: () => number = Math.random,
): number {
    const exponential = Math.min(maxMs, baseMs * 2 ** Math.max(0, attempt - 1));
    const half = exponential / 2;

    return Math.round(half + random() * half);
}

export function withTimeout<T>(
    promise: Promise<T>,
    ms: number,
    onTimeout: () => Error,
): Promise<T> {
    let timer: NodeJS.Timeout | undefined;

    const timeout = new Promise<never>((_, reject) => {
        timer = setTimeout(() => reject(onTimeout()), ms);
    });

    return Promise.race([promise, timeout]).finally(() => clearTimeout(timer));
}
