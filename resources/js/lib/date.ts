export const formatDateTime = (value: string | null, options?: Intl.DateTimeFormatOptions) =>
    value ? new Date(value).toLocaleString('en-US', options) : 'N/A';

export const formatDate = (value: string | null, options?: Intl.DateTimeFormatOptions) =>
    value
        ? new Date(value).toLocaleDateString(
              'en-US',
              options ?? {
                  month: 'short',
                  day: 'numeric',
                  year: 'numeric',
              },
          )
        : 'N/A';

const TIME_UNITS = [
    // under 1 hour → minutes
    { limit: 60 * 60, divisor: 60, label: 'minute' },
    // under 1 day → hours
    { limit: 24 * 60 * 60, divisor: 60 * 60, label: 'hour' },
    // under 7 days → days
    { limit: 7 * 24 * 60 * 60, divisor: 24 * 60 * 60, label: 'day' },
    // under 4 weeks → weeks
    { limit: 4 * 7 * 24 * 60 * 60, divisor: 7 * 24 * 60 * 60, label: 'week' },
    // under 12 months → months (30-day months as in current code)
    { limit: 12 * 30 * 24 * 60 * 60, divisor: 30 * 24 * 60 * 60, label: 'month' },
];

export const formatTimeAgo = (value: string | null): string => {
    if (!value) return 'N/A';

    const date = new Date(value);
    const now = new Date();
    const diffInSeconds = Math.floor((now.getTime() - date.getTime()) / 1000);

    if (diffInSeconds < 60) {
        return 'just now';
    }

    for (const { limit, divisor, label } of TIME_UNITS) {
        if (diffInSeconds < limit) {
            const count = Math.floor(diffInSeconds / divisor);
            const suffix = count === 1 ? '' : 's';
            return `${count} ${label}${suffix} ago`;
        }
    }

    // years (365-day years as in current code)
    const years = Math.floor(diffInSeconds / (365 * 24 * 60 * 60));
    const suffix = years === 1 ? '' : 's';
    return `${years} year${suffix} ago`;
};
