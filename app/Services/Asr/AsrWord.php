<?php

namespace App\Services\Asr;

/**
 * Represents a single word from ASR transcription with timing and confidence data.
 */
class AsrWord
{
    public function __construct(
        public readonly string $word,
        public readonly float $start,
        public readonly float $end,
        public readonly ?float $confidence = null,
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
