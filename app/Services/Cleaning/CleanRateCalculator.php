<?php

namespace App\Services\Cleaning;

class CleanRateCalculator
{
    /**
     * Calculate clean rate (confidence score) for a cleaning result.
     * Higher score = more confident the cleaning was appropriate.
     */
    public function calculate(CleaningResult $result): CleanRateResult
    {
        $score = 100;
        $penalties = [];

        // Penalty for high reduction (might have removed too much)
        $reductionPercent = $result->getReductionPercent();
        if ($reductionPercent > 50) {
            $penalty = min(30, ($reductionPercent - 50) * 0.6);
            $score -= $penalty;
            $penalties['high_reduction'] = [
                'amount' => $penalty,
                'reason' => "High text reduction: {$reductionPercent}%",
            ];
        }

        // Penalty for many bracket removals (uncertain content)
        $bracketRemovals = $this->countBracketRemovals($result->removals);
        if ($bracketRemovals > 5) {
            $penalty = min(20, ($bracketRemovals - 5) * 2);
            $score -= $penalty;
            $penalties['bracket_removals'] = [
                'amount' => $penalty,
                'reason' => "Many bracket removals: {$bracketRemovals}",
            ];
        }

        // Penalty for many parenthesis removals
        $parenRemovals = $this->countParenRemovals($result->removals);
        if ($parenRemovals > 3) {
            $penalty = min(15, ($parenRemovals - 3) * 2);
            $score -= $penalty;
            $penalties['paren_removals'] = [
                'amount' => $penalty,
                'reason' => "Many parenthesis removals: {$parenRemovals}",
            ];
        }

        // Penalty for very short result
        $cleanedWords = $result->getMetrics()['cleaned_words'];
        if ($cleanedWords < 50) {
            $penalty = min(20, (50 - $cleanedWords) * 0.4);
            $score -= $penalty;
            $penalties['short_result'] = [
                'amount' => $penalty,
                'reason' => "Short result: {$cleanedWords} words",
            ];
        }

        // Ensure score stays in range
        $score = max(0, min(100, $score));

        return new CleanRateResult(
            score: (int) round($score),
            category: $this->categorize($score),
            penalties: $penalties,
        );
    }

    protected function countBracketRemovals(array $removals): int
    {
        return count(array_filter($removals, fn($r) => str_starts_with($r, '[')));
    }

    protected function countParenRemovals(array $removals): int
    {
        return count(array_filter($removals, fn($r) => str_starts_with($r, '(')));
    }

    protected function categorize(float $score): string
    {
        $thresholds = config('cleaning.clean_rate_thresholds');

        if ($score >= $thresholds['excellent']) {
            return 'excellent';
        }
        if ($score >= $thresholds['good']) {
            return 'good';
        }
        if ($score >= $thresholds['moderate']) {
            return 'moderate';
        }
        if ($score >= $thresholds['low']) {
            return 'low';
        }
        return 'poor';
    }
}
