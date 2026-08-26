/**
 * Format an ISO timestamp as a plain calendar date.
 */
export function formatDate(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return new Intl.DateTimeFormat(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    }).format(date);
}

/**
 * The display form of an API key: its stored prefix, then an ellipsis for the
 * secret part Bilis never keeps.
 */
export function maskedKey(keyPrefix: string): string {
    return `${keyPrefix}…`;
}
