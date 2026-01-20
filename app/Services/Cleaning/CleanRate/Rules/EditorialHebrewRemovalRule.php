<?php

namespace App\Services\Cleaning\CleanRate\Rules;

use App\Services\Cleaning\CleanRate\AbstractCleanRateRule;

/**
 * Score based on editorial Hebrew detection confidence.
 *
 * Some Hebrew is clearly editorial (citations, cross-references).
 * Some is uncertain (could be quotes or spoken content).
 */
class EditorialHebrewRemovalRule extends AbstractCleanRateRule
{
    protected string $name = 'editorial_hebrew_removal';

    protected string $description = 'Scores based on editorial Hebrew detection confidence';

    protected int $maxPenalty = 25;

    public function appliesTo(array $removedItem): bool
    {
        return $this->matchesKeywords($removedItem, ['editorial', 'hebrew']);
    }

    public function calculatePenalty(array $removedItem, ?array $context = null): int
    {
        if (! $this->appliesTo($removedItem)) {
            return 0;
        }

        $reason = strtolower($removedItem['reason'] ?? '');

        // High confidence editorial patterns
        $highConfidencePatterns = [
            'citation',
            'reference',
            'cross-ref',
            'see ',
            'עיין',
            'ראה',
        ];

        foreach ($highConfidencePatterns as $pattern) {
            if (str_contains($reason, $pattern)) {
                return 0;
            }
        }

        if (str_contains($reason, 'position marker')) {
            return 1;
        }

        return 4; // Unknown editorial Hebrew
    }
}
