<?php

namespace App\Services\Cleaning\CleanRate\Rules;

use App\Services\Cleaning\CleanRate\AbstractCleanRateRule;

/**
 * No penalty for force-removed patterns.
 *
 * Force remove patterns are explicitly configured by the user,
 * so we have high confidence they should be removed.
 */
class ForceRemoveRule extends AbstractCleanRateRule
{
    protected string $name = 'force_remove';
    protected string $description = 'No penalty for explicitly configured removal patterns';
    protected int $maxPenalty = 0;

    public function appliesTo(array $removedItem): bool
    {
        return $this->matchesKeywords($removedItem, ['force']);
    }

    public function calculatePenalty(array $removedItem, ?array $context = null): int
    {
        return 0; // User explicitly wanted this removed
    }
}
