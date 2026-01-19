<?php

namespace App\Services\Cleaning\Processors;

class ProcessorResult
{
    public function __construct(
        public readonly string $text,
        public readonly array $removals = [],
        public readonly int $changesCount = 0,
    ) {}

    public static function unchanged(string $text): self
    {
        return new self($text, [], 0);
    }

    public static function changed(string $text, array $removals, int $changesCount): self
    {
        return new self($text, $removals, $changesCount);
    }
}
