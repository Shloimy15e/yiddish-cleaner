<?php

namespace App\Services\Alignment;

/**
 * Represents a single aligned word with timing information.
 */
class AlignedWord
{
    public function __construct(
        public readonly string $word,
        public readonly float $start,
        public readonly float $end,
        public readonly ?float $confidence = null,
        public readonly ?int $charStart = null,
        public readonly ?int $charEnd = null,
    ) {}

    /**
     * Create from array (e.g., from API response).
     */
    public static function fromArray(array $data): self
    {
        return new self(
            word: $data['word'] ?? $data['text'] ?? '',
            start: (float) ($data['start'] ?? $data['start_time'] ?? 0),
            end: (float) ($data['end'] ?? $data['end_time'] ?? 0),
            confidence: isset($data['confidence']) ? (float) $data['confidence'] : null,
            charStart: isset($data['char_start']) ? (int) $data['char_start'] : null,
            charEnd: isset($data['char_end']) ? (int) $data['char_end'] : null,
        );
    }

    /**
     * Convert to array for storage.
     */
    public function toArray(): array
    {
        return [
            'word' => $this->word,
            'start' => $this->start,
            'end' => $this->end,
            'confidence' => $this->confidence,
            'char_start' => $this->charStart,
            'char_end' => $this->charEnd,
        ];
    }

    /**
     * Get word duration in seconds.
     */
    public function getDuration(): float
    {
        return $this->end - $this->start;
    }
}
