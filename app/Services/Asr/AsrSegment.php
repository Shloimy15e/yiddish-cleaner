<?php

namespace App\Services\Asr;

/**
 * Represents a segment (sentence/phrase) from ASR transcription with timing and confidence data.
 */
class AsrSegment
{
    /**
     * @param  string  $text  The segment text
     * @param  float  $start  Start time in seconds
     * @param  float  $end  End time in seconds
     * @param  float|null  $confidence  Confidence score (0-1), derived from avg_logprob
     * @param  array|null  $words  Optional embedded word-level timing for playback alignment
     */
    public function __construct(
        public readonly string $text,
        public readonly float $start,
        public readonly float $end,
        public readonly ?float $confidence = null,
        public readonly ?array $words = null,
    ) {}

    /**
     * Create from array (e.g., from API response).
     */
    public static function fromArray(array $data): self
    {
        return new self(
            text: $data['text'] ?? '',
            start: (float) ($data['start'] ?? $data['start_time'] ?? 0),
            end: (float) ($data['end'] ?? $data['end_time'] ?? 0),
            confidence: isset($data['confidence']) ? (float) $data['confidence'] : null,
            words: $data['words'] ?? null,
        );
    }

    /**
     * Convert to array for storage.
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'start' => $this->start,
            'end' => $this->end,
            'confidence' => $this->confidence,
            'words' => $this->words,
        ];
    }

    /**
     * Get segment duration in seconds.
     */
    public function getDuration(): float
    {
        return $this->end - $this->start;
    }

    /**
     * Get approximate word count for this segment.
     */
    public function getWordCount(): int
    {
        return str_word_count($this->text);
    }

    /**
     * Check if this segment has embedded word-level timing.
     */
    public function hasWords(): bool
    {
        return $this->words !== null && count($this->words) > 0;
    }
}
