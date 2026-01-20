<?php

namespace App\Services\Cleaning\CleanRate\Rules;

use App\Services\Cleaning\CleanRate\AbstractCleanRateRule;

/**
 * No penalty for whitespace normalization.
 *
 * Normalizing whitespace doesn't remove content.
 */
class WhitespaceRemovalRule extends AbstractCleanRateRule
{
    protected string $name = 'whitespace_removal';

    protected string $description = 'No penalty for whitespace normalization';

    protected int $maxPenalty = 0;

    public function appliesTo(array $removedItem): bool
    {
        return $this->matchesKeywords($removedItem, ['whitespace']);
    }

    public function calculatePenalty(array $removedItem, ?array $context = null): int
    {
        return 0;
    }
}
