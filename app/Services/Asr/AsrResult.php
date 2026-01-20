<?php

namespace App\Services\Asr;

class AsrResult
{
    public function __construct(
        public readonly string $text,
        public readonly string $provider,
        public readonly string $model,
        public readonly ?float $durationSeconds = null,
        public readonly ?int $wordCount = null,
        public readonly ?string $summary = null,
        public readonly array $keywords = [],
        public readonly array $metadata = [],
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
        ];
    }
}
