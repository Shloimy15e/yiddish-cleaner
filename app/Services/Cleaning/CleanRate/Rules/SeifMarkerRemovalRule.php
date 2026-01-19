<?php

namespace App\Services\Cleaning\CleanRate\Rules;

use App\Services\Cleaning\CleanRate\AbstractCleanRateRule;

/**
 * Minimal penalty for seif marker removal.
 *
 * Seif markers (Hebrew gematria numbering) are clearly structural,
 * not spoken content. High confidence removal.
 */
class SeifMarkerRemovalRule extends AbstractCleanRateRule
{
    protected string $name = 'seif_marker_removal';
    protected string $description = 'Minimal penalty for seif/gematria markers (clearly structural)';
    protected int $maxPenalty = 5;

    public function appliesTo(array $removedItem): bool
    {
        return $this->matchesKeywords($removedItem, ['seif', 'gematria']);
    }

    public function calculatePenalty(array $removedItem, ?array $context = null): int
    {
        return 0; // Seif markers are clearly not spoken
    }
}
