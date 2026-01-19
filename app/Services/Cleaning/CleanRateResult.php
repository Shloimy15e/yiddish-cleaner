<?php

namespace App\Services\Cleaning;

class CleanRateResult
{
    public function __construct(
        public readonly int $score,
        public readonly string $category,
        public readonly array $penalties,
    ) {}
}
