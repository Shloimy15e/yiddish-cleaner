<?php

namespace App\Services\Cleaning;

use App\Services\Cleaning\CleanRate\CleanRateRuleInterface;
use App\Services\Cleaning\CleanRate\Rules\BracketRemovalRule;
use App\Services\Cleaning\CleanRate\Rules\EditorialHebrewRemovalRule;
use App\Services\Cleaning\CleanRate\Rules\ForceRemoveRule;
use App\Services\Cleaning\CleanRate\Rules\ParenthesesRemovalRule;
use App\Services\Cleaning\CleanRate\Rules\SeifMarkerRemovalRule;
use App\Services\Cleaning\CleanRate\Rules\SpecialCharsRemovalRule;
use App\Services\Cleaning\CleanRate\Rules\TitleStyleRemovalRule;
use App\Services\Cleaning\CleanRate\Rules\UnknownRemovalRule;
use App\Services\Cleaning\CleanRate\Rules\WhitespaceRemovalRule;

/**
 * Calculates clean rate scores using registered rules.
 *
 * The calculator maintains a list of rules that are applied to each removed item.
 * Rules are checked in order, and the first matching rule's penalty is used.
 */
class CleanRateCalculator
{
    /** @var CleanRateRuleInterface[] */
    protected array $rules;

    public function __construct(?array $rules = null)
    {
        $this->rules = $rules ?? $this->getDefaultRules();
    }

    /**
     * Get default rules in priority order (first match wins).
     *
     * @return CleanRateRuleInterface[]
     */
    protected function getDefaultRules(): array
    {
        return [
            new SpecialCharsRemovalRule(),
            new WhitespaceRemovalRule(),
            new SeifMarkerRemovalRule(),
            new ForceRemoveRule(),
            new TitleStyleRemovalRule(),
            new BracketRemovalRule(),
            new ParenthesesRemovalRule(),
            new EditorialHebrewRemovalRule(),
            new UnknownRemovalRule(), // Fallback - must be last
        ];
    }

    /**
     * Calculate clean rate (confidence score) for a cleaning result.
     * Higher score = more confident the cleaning was appropriate.
     */
    public function calculate(CleaningResult $result): CleanRateResult
    {
        $removedItems = $this->buildRemovedItems($result);

        $totalPenalty = 0;
        $penalties = [];

        foreach ($removedItems as $item) {
            // Find first matching rule
            foreach ($this->rules as $rule) {
                if ($rule->appliesTo($item)) {
                    $penalty = $rule->calculatePenalty($item);
                    if ($penalty > 0) {
                        $totalPenalty += $penalty;
                        $text = $item['text'] ?? '';
                        $penalties[$rule->getName()] = [
                            'amount' => ($penalties[$rule->getName()]['amount'] ?? 0) + $penalty,
                            'count' => ($penalties[$rule->getName()]['count'] ?? 0) + 1,
                            'reason' => $rule->getDescription(),
                            'preview' => mb_strlen($text) > 50 ? mb_substr($text, 0, 50) . '...' : $text,
                        ];
                    }
                    break; // First matching rule wins
                }
            }
        }

        // Apply global penalties
        $globalPenalties = $this->calculateGlobalPenalties($result);
        foreach ($globalPenalties as $name => $penalty) {
            $totalPenalty += $penalty['amount'];
            $penalties[$name] = $penalty;
        }

        // Calculate final score (100 - penalties, capped at 0)
        $score = max(0, 100 - $totalPenalty);

        return new CleanRateResult(
            score: (int) round($score),
            category: $this->categorize($score),
            penalties: $penalties,
        );
    }

    /**
     * Build removed items array from CleaningResult.
     */
    protected function buildRemovedItems(CleaningResult $result): array
    {
        $items = [];

        foreach ($result->processorResults as $processor => $data) {
            foreach ($data['removals'] ?? [] as $removal) {
                $items[] = [
                    'text' => $removal,
                    'processor' => $processor,
                    'reason' => $processor,
                ];
            }
        }

        return $items;
    }

    /**
     * Calculate global penalties not tied to specific removals.
     */
    protected function calculateGlobalPenalties(CleaningResult $result): array
    {
        $penalties = [];

        // Penalty for high reduction (might have removed too much)
        $reductionPercent = $result->getReductionPercent();
        if ($reductionPercent > 50) {
            $penalty = min(30, ($reductionPercent - 50) * 0.6);
            $penalties['high_reduction'] = [
                'amount' => $penalty,
                'reason' => "High text reduction: {$reductionPercent}%",
            ];
        }

        // Penalty for very short result
        $cleanedWords = $result->getMetrics()['cleaned_words'];
        if ($cleanedWords < 50) {
            $penalty = min(20, (50 - $cleanedWords) * 0.4);
            $penalties['short_result'] = [
                'amount' => $penalty,
                'reason' => "Short result: {$cleanedWords} words",
            ];
        }

        return $penalties;
    }

    /**
     * Add a rule to the calculator.
     *
     * @param CleanRateRuleInterface $rule The rule to add
     * @param int|null $priority Position in the rule list (lower = higher priority).
     *                           If null, adds before the fallback rule.
     */
    public function addRule(CleanRateRuleInterface $rule, ?int $priority = null): void
    {
        if ($priority !== null) {
            array_splice($this->rules, $priority, 0, [$rule]);
        } else {
            // Insert before the last rule (fallback)
            array_splice($this->rules, -1, 0, [$rule]);
        }
    }

    /**
     * Remove a rule by name.
     *
     * @return bool True if rule was found and removed
     */
    public function removeRule(string $ruleName): bool
    {
        foreach ($this->rules as $i => $rule) {
            if ($rule->getName() === $ruleName) {
                array_splice($this->rules, $i, 1);
                return true;
            }
        }
        return false;
    }

    /**
     * Get information about all registered rules.
     */
    public function getRuleInfo(): array
    {
        return array_map(fn ($rule) => [
            'name' => $rule->getName(),
            'description' => $rule->getDescription(),
            'max_penalty' => $rule->getMaxPenalty(),
        ], $this->rules);
    }

    /**
     * Categorize score into descriptive category.
     */
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
