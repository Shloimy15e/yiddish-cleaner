<?php

namespace App\Services\Cleaning\CleanRate\Rules;

use App\Services\Cleaning\CleanRate\AbstractCleanRateRule;

/**
 * Score based on confidence of title detection.
 *
 * High confidence removals:
 * - Word heading styles (Heading 1, Title, etc.)
 * - Large font combined with bold
 * - Very short "paragraphs" (likely headers)
 *
 * Lower confidence:
 * - Just bold text
 * - Just large font
 */
class TitleStyleRemovalRule extends AbstractCleanRateRule
{
    protected string $name = 'title_style_removal';
    protected string $description = 'Scores based on confidence of title detection';
    protected int $maxPenalty = 15;

    public function appliesTo(array $removedItem): bool
    {
        return $this->matchesKeywords($removedItem, ['title', 'heading']);
    }

    public function calculatePenalty(array $removedItem, ?array $context = null): int
    {
        if (!$this->appliesTo($removedItem)) {
            return 0;
        }

        $reason = strtolower($removedItem['reason'] ?? '');

        // Check confidence indicators
        $hasHeadingStyle = str_contains($reason, 'heading style') || str_contains($reason, 'word style');
        $hasLargeFont = str_contains($reason, 'large font') || str_contains($reason, 'larger than');
        $hasBold = str_contains($reason, 'bold');
        $isShort = str_contains($reason, 'short paragraph') || str_contains($reason, 'few words');

        // Count confidence indicators
        $confidenceIndicators = (int) $hasHeadingStyle + (int) $hasLargeFont + (int) $hasBold + (int) $isShort;

        if ($confidenceIndicators >= 3) {
            return 0; // Very confident - no penalty
        } elseif ($confidenceIndicators === 2) {
            return 1; // Confident
        } elseif ($confidenceIndicators === 1) {
            return 3; // Somewhat confident
        }

        return 5; // Low confidence title removal
    }
}
