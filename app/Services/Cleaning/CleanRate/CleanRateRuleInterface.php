<?php

namespace App\Services\Cleaning\CleanRate;

/**
 * Interface for clean rate scoring rules.
 */
interface CleanRateRuleInterface
{
    /**
     * Get the unique name of this rule.
     */
    public function getName(): string;

    /**
     * Get a description of what this rule checks.
     */
    public function getDescription(): string;

    /**
     * Get the maximum penalty this rule can apply.
     */
    public function getMaxPenalty(): int;

    /**
     * Check if this rule applies to the given removed item.
     */
    public function appliesTo(array $removedItem): bool;

    /**
     * Calculate penalty points for a removed item.
     *
     * @param array $removedItem Dict with info about what was removed, including:
     *                           - 'text': The removed text
     *                           - 'reason': Why it was removed (processor name + details)
     *                           - 'processor': Which processor removed it
     * @param array|null $context Optional processing context (paragraph styles, etc.)
     * @return int Penalty points to subtract (0 = no penalty, higher = more uncertain)
     */
    public function calculatePenalty(array $removedItem, ?array $context = null): int;
}
