export const cleanRateCategoryClasses = {
    excellent: 'clean-rate-excellent',
    good: 'clean-rate-good',
    moderate: 'clean-rate-moderate',
    low: 'clean-rate-low',
    poor: 'clean-rate-poor',
} as const;

export type CleanRateCategory = keyof typeof cleanRateCategoryClasses;

export const getCleanRateCategoryClass = (category: string | null) => {
    const key = (category ?? '') as CleanRateCategory;
    return cleanRateCategoryClasses[key] ?? 'bg-muted text-muted-foreground';
};
