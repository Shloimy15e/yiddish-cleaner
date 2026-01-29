<?php

namespace App\Services\Asr;

class AsrResult
{
    /**
     * @param  string  $text  Full transcription text
     * @param  string  $provider  ASR provider name
     * @param  string  $model  Model used for transcription
     * @param  float|null  $durationSeconds  Audio duration
     * @param  int|null  $wordCount  Word count
     * @param  string|null  $summary  Summary (if provided by ASR)
     * @param  array  $keywords  Keywords (if provided by ASR)
     * @param  array  $metadata  Additional metadata
     * @param  AsrWord[]|null  $words  Word-level timing and confidence data (legacy)
     * @param  AsrSegment[]|null  $segments  Segment-level timing and confidence data
     */
    public function __construct(
        public readonly string $text,
        public readonly string $provider,
        public readonly string $model,
        public readonly ?float $durationSeconds = null,
        public readonly ?int $wordCount = null,
        public readonly ?string $summary = null,
        public readonly array $keywords = [],
        public readonly array $metadata = [],
        public readonly ?array $words = null,
        public readonly ?array $segments = null,
    ) {}

    /**
     * Get a hash of the transcription text for deduplication.
     */
    public function getTextHash(): string
    {
        return hash('sha256', $this->text);
    }

    /**
     * Calculate word count if not provided.
     */
    public function getWordCount(): int
    {
        if ($this->wordCount !== null) {
            return $this->wordCount;
        }

        return str_word_count($this->text);
    }

    /**
     * Convert to array for storage.
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'provider' => $this->provider,
            'model' => $this->model,
            'duration_seconds' => $this->durationSeconds,
            'word_count' => $this->getWordCount(),
            'summary' => $this->summary,
            'keywords' => $this->keywords,
            'metadata' => $this->metadata,
            'words' => $this->words ? array_map(fn (AsrWord $w) => $w->toArray(), $this->words) : null,
            'segments' => $this->segments ? array_map(fn (AsrSegment $s) => $s->toArray(), $this->segments) : null,
        ];
    }

    /**
     * Check if word-level data is available.
     */
    public function hasWords(): bool
    {
        return $this->words !== null && count($this->words) > 0;
    }

    /**
     * Check if segment-level data is available.
     */
    public function hasSegments(): bool
    {
        return $this->segments !== null && count($this->segments) > 0;
    }
}
