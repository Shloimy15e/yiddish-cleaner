<?php

namespace App\Services\Alignment;

/**
 * Result of a forced alignment operation.
 */
class AlignmentResult
{
    /**
     * @param  string  $text  The aligned text
     * @param  string  $provider  Alignment provider name
     * @param  string  $model  Model used for alignment
     * @param  AlignedWord[]  $words  Word-level timing data
     * @param  float|null  $durationSeconds  Audio duration
     * @param  array  $metadata  Additional metadata from the provider
     */
    public function __construct(
        public readonly string $text,
        public readonly string $provider,
        public readonly string $model,
        public readonly array $words,
        public readonly ?float $durationSeconds = null,
        public readonly array $metadata = [],
    ) {}

    /**
     * Get the number of aligned words.
     */
    public function getWordCount(): int
    {
        return count($this->words);
    }

    /**
     * Check if alignment produced word-level data.
     */
    public function hasWords(): bool
    {
        return count($this->words) > 0;
    }

    /**
     * Get the aligned text from words.
     */
    public function getAlignedText(): string
    {
        return implode(' ', array_map(fn (AlignedWord $w) => $w->word, $this->words));
    }

    /**
     * Get average confidence score.
     */
    public function getAverageConfidence(): ?float
    {
        $confidences = array_filter(
            array_map(fn (AlignedWord $w) => $w->confidence, $this->words),
            fn ($c) => $c !== null
        );

        if (empty($confidences)) {
            return null;
        }

        return array_sum($confidences) / count($confidences);
    }

    /**
     * Get words below a confidence threshold.
     *
     * @return AlignedWord[]
     */
    public function getLowConfidenceWords(float $threshold = 0.7): array
    {
        return array_filter(
            $this->words,
            fn (AlignedWord $w) => $w->confidence !== null && $w->confidence < $threshold
        );
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
            'words' => array_map(fn (AlignedWord $w) => $w->toArray(), $this->words),
            'duration_seconds' => $this->durationSeconds,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Create from array (e.g., from stored data).
     */
    public static function fromArray(array $data): self
    {
        return new self(
            text: $data['text'] ?? '',
            provider: $data['provider'] ?? 'unknown',
            model: $data['model'] ?? 'unknown',
            words: array_map(fn ($w) => AlignedWord::fromArray($w), $data['words'] ?? []),
            durationSeconds: $data['duration_seconds'] ?? null,
            metadata: $data['metadata'] ?? [],
        );
    }
}
