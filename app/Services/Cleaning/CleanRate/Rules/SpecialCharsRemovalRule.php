<?php

namespace App\Services\Cleaning\CleanRate\Rules;

use App\Services\Cleaning\CleanRate\AbstractCleanRateRule;

/**
 * No penalty for special character removal.
 *
 * Zero-width spaces, BOMs, and invisible Unicode are never spoken content.
 */
class SpecialCharsRemovalRule extends AbstractCleanRateRule
{
    protected string $name = 'special_chars_removal';
    protected string $description = 'No penalty for invisible character removal';
    protected int $maxPenalty = 0;

    public function appliesTo(array $removedItem): bool
    {
        return $this->matchesKeywords($removedItem, ['special', 'unicode', 'zero-width']);
    }

    public function calculatePenalty(array $removedItem, ?array $context = null): int
    {
        return 0; // Invisible characters are never spoken
    }
}
