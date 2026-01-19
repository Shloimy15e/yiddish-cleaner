<?php

namespace App\Services\Cleaning\CleanRate;

/**
 * Base class for clean rate scoring rules.
 */
abstract class AbstractCleanRateRule implements CleanRateRuleInterface
{
    protected string $name = 'base_rule';
    protected string $description = 'Base rule';
    protected int $maxPenalty = 100;

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getMaxPenalty(): int
    {
        return $this->maxPenalty;
    }

    public function appliesTo(array $removedItem): bool
    {
        return true;
    }

    /**
     * Check if item matches any of the given keywords in reason or processor.
     */
    protected function matchesKeywords(array $removedItem, array $keywords): bool
    {
        $reason = strtolower($removedItem['reason'] ?? '');
        $processor = strtolower($removedItem['processor'] ?? '');

        foreach ($keywords as $keyword) {
            if (str_contains($reason, $keyword) || str_contains($processor, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
