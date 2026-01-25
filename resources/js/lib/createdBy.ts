export interface CreatorInfo {
    id?: number | null;
    name?: string | null;
}

export const formatCreatedBy = (
    creator?: CreatorInfo | null,
    currentUser?: CreatorInfo | null,
): string => {
    return creator?.name || 'Unknown';
};
