<?php

namespace App\Services\Cleaning\CleanRate\Rules;

use App\Services\Cleaning\CleanRate\AbstractCleanRateRule;

/**
 * Penalize removal of bracketed content.
 *
 * Brackets are often editorial notes, but sometimes contain spoken content.
 * Inline brackets are more likely editorial; full paragraphs less certain.
 */
class BracketRemovalRule extends AbstractCleanRateRule
{
    protected string $name = 'bracket_removal';
    protected string $description = 'Penalizes removal of bracketed content (uncertain if editorial or spoken)';
    protected int $maxPenalty = 30;

    // Points per bracket removal
    private const INLINE_BRACKET_PENALTY = 2;
    private const FULL_PARAGRAPH_BRACKET_PENALTY = 8;

    public function appliesTo(array $removedItem): bool
    {
        return $this->matchesKeywords($removedItem, ['bracket']);
    }

    public function calculatePenalty(array $removedItem, ?array $context = null): int
    {
        if (!$this->appliesTo($removedItem)) {
            return 0;
        }

        $text = $removedItem['text'] ?? '';
        $reason = strtolower($removedItem['reason'] ?? '');

        // Check if it's inline or full paragraph
        if (str_contains($reason, 'inline')) {
            return self::INLINE_BRACKET_PENALTY;
        } elseif (str_contains($reason, 'full paragraph') || str_contains($reason, 'entire paragraph')) {
            return self::FULL_PARAGRAPH_BRACKET_PENALTY;
        }

        // Default bracket penalty - longer text = more uncertain
        if (mb_strlen($text) > 100) {
            return 5;
        }

        return self::INLINE_BRACKET_PENALTY;
    }
}
