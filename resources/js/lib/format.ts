const time = new Intl.DateTimeFormat(undefined, {
    hour: '2-digit',
    minute: '2-digit',
});
const shortDate = new Intl.DateTimeFormat(undefined, {
    day: 'numeric',
    month: 'short',
});
const longDate = new Intl.DateTimeFormat(undefined, {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
});

function sameDay(a: Date, b: Date): boolean {
    return a.toDateString() === b.toDateString();
}

/** "14:05" today, "Yesterday", or "12 Oct" for list timestamps. */
export function formatListTimestamp(iso: string | null): string {
    if (!iso) {
        return '';
    }

    const date = new Date(iso);
    const now = new Date();
    const yesterday = new Date(now);
    yesterday.setDate(now.getDate() - 1);

    if (sameDay(date, now)) {
        return time.format(date);
    }

    if (sameDay(date, yesterday)) {
        return 'Yesterday';
    }

    return shortDate.format(date);
}

export function formatTime(iso: string | null): string {
    return iso ? time.format(new Date(iso)) : '';
}

/** Label for chat date separators. */
export function formatDayLabel(iso: string): string {
    const date = new Date(iso);
    const now = new Date();
    const yesterday = new Date(now);
    yesterday.setDate(now.getDate() - 1);

    if (sameDay(date, now)) {
        return 'Today';
    }

    if (sameDay(date, yesterday)) {
        return 'Yesterday';
    }

    return longDate.format(date);
}

export function dayKey(iso: string | null): string {
    return iso ? new Date(iso).toDateString() : '';
}

export function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(0)} KB`;
    }

    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}
