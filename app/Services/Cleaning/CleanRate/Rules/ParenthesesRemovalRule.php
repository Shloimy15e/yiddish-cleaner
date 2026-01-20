<?php

namespace App\Services\Cleaning\CleanRate\Rules;

use App\Services\Cleaning\CleanRate\AbstractCleanRateRule;

/**
 * Penalize removal of parenthetical content.
 *
 * In Yiddish transcripts, most parenthetical content is spoken.
 * Removing it is risky unless it matches known editorial patterns.
 */
class ParenthesesRemovalRule extends AbstractCleanRateRule
{
    protected string $name = 'parentheses_removal';

    protected string $description = 'Penalizes removal of parenthetical content (often spoken in Yiddish transcripts)';

    protected int $maxPenalty = 40;

    private const CITATION_PENALTY = 1;

    private const STAGE_DIRECTION_PENALTY = 2;

    private const UNKNOWN_PARENS_PENALTY = 6;

    public function appliesTo(array $removedItem): bool
    {
        return $this->matchesKeywords($removedItem, ['parenthes', 'parens']);
    }

    public function calculatePenalty(array $removedItem, ?array $context = null): int
    {
        if (! $this->appliesTo($removedItem)) {
            return 0;
        }

        $reason = strtolower($removedItem['reason'] ?? '');

        if (
            str_contains($reason, 'citation') ||
            str_contains($reason, 'source') ||
            str_contains($reason, 'reference')
        ) {
            return self::CITATION_PENALTY;
        } elseif (
            str_contains($reason, 'stage direction') ||
            str_contains($reason, 'editorial')
        ) {
            return self::STAGE_DIRECTION_PENALTY;
        }

        return self::UNKNOWN_PARENS_PENALTY;
    }
}
