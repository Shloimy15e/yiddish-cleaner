<?php

namespace App\Services\Cleaning\CleanRate\Rules;

use App\Services\Cleaning\CleanRate\AbstractCleanRateRule;

/**
 * Default penalty for unrecognized removals.
 *
 * If we don't recognize the removal type, apply moderate penalty.
 * This should be the last rule in the chain (fallback).
 */
class UnknownRemovalRule extends AbstractCleanRateRule
{
    protected string $name = 'unknown_removal';
    protected string $description = 'Default penalty for unrecognized removal types';
    protected int $maxPenalty = 30;

    public function appliesTo(array $removedItem): bool
    {
        return true; // Fallback rule - always applies
    }

    public function calculatePenalty(array $removedItem, ?array $context = null): int
    {
        // This should only be called if no other rule matched
        return 3;
    }
}
