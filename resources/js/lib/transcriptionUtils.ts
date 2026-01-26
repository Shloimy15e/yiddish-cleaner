import * as Diff from 'diff';

import type { AlignmentItem, DiffSegment } from '@/types/transcription-show';

/**
 * Normalize text for WER comparison.
 *
 * Strips:
 * - Hebrew/Yiddish nikkud (vowel points): U+05B0-U+05BD, U+05BF, U+05C1-U+05C2, U+05C4-U+05C5, U+05C7
 * - Hebrew cantillation marks (trop): U+0591-U+05AF
 * - Punctuation and symbols
 * - Excess whitespace
 */
export const normalizeForWer = (text: string): string => {
    // Convert to lowercase
    let normalized = text.toLowerCase();

    // Remove Hebrew nikkud (vowel points)
    normalized = normalized.replace(/[\u05B0-\u05BD\u05BF\u05C1\u05C2\u05C4\u05C5\u05C7]/g, '');

    // Remove Hebrew cantillation marks (trop/teamim)
    normalized = normalized.replace(/[\u0591-\u05AF]/g, '');

    // Remove all combining diacritical marks (Unicode category M, matching backend PHP normalization)
    normalized = normalized.normalize('NFD').replace(/\p{M}+/gu, '');

    // Remove punctuation and symbols (keep letters, numbers, spaces)
    normalized = normalized.replace(/[^\p{L}\p{N}\s]/gu, '');

    // Normalize whitespace
    normalized = normalized.replace(/\s+/g, ' ').trim();

    return normalized;
};

/**
 * Tokenize a string into words (with normalization for WER)
 */
export const tokenize = (value: string): string[] =>
    (normalizeForWer(value).match(/[^\s]+/g) || []).filter(Boolean);

/**
 * Build alignment from diff between reference and hypothesis text
 * Uses normalized text for accurate WER calculation
 */
export const buildAlignmentFromDiff = (
    refText: string,
    hypText: string
): AlignmentItem[] => {
    const refTokens = tokenize(refText);
    const hypTokens = tokenize(hypText);
    const parts = Diff.diffArrays(refTokens, hypTokens);
    const alignment: AlignmentItem[] = [];

    let i = 0;
    while (i < parts.length) {
        const part = parts[i];
        const next = parts[i + 1];

        if (part.removed && next?.added) {
            const removedWords = part.value as string[];
            const addedWords = next.value as string[];
            const pairCount = Math.min(removedWords.length, addedWords.length);

            for (let idx = 0; idx < pairCount; idx++) {
                alignment.push({
                    type: 'sub',
                    ref: removedWords[idx],
                    hyp: addedWords[idx],
                });
            }
            for (let idx = pairCount; idx < removedWords.length; idx++) {
                alignment.push({ type: 'del', ref: removedWords[idx], hyp: null });
            }
            for (let idx = pairCount; idx < addedWords.length; idx++) {
                alignment.push({ type: 'ins', ref: null, hyp: addedWords[idx] });
            }
            i += 2;
            continue;
        }

        if (part.added) {
            (part.value as string[]).forEach((word) => {
                alignment.push({ type: 'ins', ref: null, hyp: word });
            });
        } else if (part.removed) {
            (part.value as string[]).forEach((word) => {
                alignment.push({ type: 'del', ref: word, hyp: null });
            });
        } else {
            (part.value as string[]).forEach((word) => {
                alignment.push({ type: 'correct', ref: word, hyp: word });
            });
        }
        i += 1;
    }
    return alignment;
};

/**
 * Format status label
 */
export const formatStatus = (status: string): string => {
    const map: Record<string, string> = {
        pending: 'Pending',
        processing: 'Processing',
        completed: 'Completed',
        failed: 'Failed',
    };
    return map[status] ?? status;
};

/**
 * Get CSS class for status badge
 */
export const statusClass = (status: string): string => {
    const map: Record<string, string> = {
        pending:
            'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
        processing:
            'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
        completed:
            'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400',
        failed: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
    };
    return map[status] ?? 'bg-muted text-muted-foreground';
};

/**
 * Generate diff segments between two texts
 */
export const generateDiff = (
    originalText: string,
    cleanedText: string
): DiffSegment[] => {
    const parts = Diff.diffWordsWithSpace(originalText, cleanedText);
    const segments: DiffSegment[] = [];

    for (const part of parts) {
        if (part.added) {
            segments.push({ type: 'added', text: part.value });
        } else if (part.removed) {
            segments.push({ type: 'removed', text: part.value });
        } else {
            segments.push({ type: 'same', text: part.value });
        }
    }

    return segments;
};

/**
 * Calculate diff statistics
 */
export const calculateDiffStats = (
    diff: DiffSegment[]
): { additions: number; deletions: number } => {
    let additions = 0;
    let deletions = 0;

    for (const segment of diff) {
        if (segment.type === 'added') {
            additions += segment.text.split(/\s+/).filter(Boolean).length;
        } else if (segment.type === 'removed') {
            deletions += segment.text.split(/\s+/).filter(Boolean).length;
        }
    }

    return { additions, deletions };
};
