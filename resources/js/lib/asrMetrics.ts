export const normalizeErrorRate = (rate: number | null | undefined): number | null => {
    if (rate === null || rate === undefined) return null;
    return rate > 1 ? rate / 100 : rate;
};

export const formatErrorRate = (rate: number | null | undefined): string => {
    const normalized = normalizeErrorRate(rate);
    if (normalized === null) return 'N/A';
    return `${(normalized * 100).toFixed(2)}%`;
};

export const getWerColor = (
    rate: number | null | undefined,
    scheme: 'detailed' | 'benchmark' = 'detailed',
): string => {
    const normalized = normalizeErrorRate(rate);
    if (normalized === null) return 'text-muted-foreground';

    if (scheme === 'benchmark') {
        if (normalized < 0.1) return 'text-green-600 dark:text-green-400';
        if (normalized < 0.2) return 'text-yellow-600 dark:text-yellow-400';
        if (normalized < 0.3) return 'text-orange-600 dark:text-orange-400';
        return 'text-red-600 dark:text-red-400';
    }

    if (normalized <= 0.1) return 'text-emerald-600 dark:text-emerald-400';
    if (normalized <= 0.2) return 'text-green-600 dark:text-green-400';
    if (normalized <= 0.3) return 'text-yellow-600 dark:text-yellow-400';
    if (normalized <= 0.5) return 'text-orange-600 dark:text-orange-400';
    return 'text-red-600 dark:text-red-400';
};
