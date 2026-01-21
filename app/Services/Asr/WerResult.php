<?php

namespace App\Services\Asr;

class WerResult
{
    public function __construct(
        public readonly ?float $wer,
        public readonly ?float $cer,
        public readonly int $substitutions,
        public readonly int $insertions,
        public readonly int $deletions,
        public readonly int $referenceWords,
        public readonly int $hypothesisWords,
        public readonly array $errors = [],
    ) {}

    /**
     * Get total number of errors.
     */
    public function getTotalErrors(): int
    {
        return $this->substitutions + $this->insertions + $this->deletions;
    }

    /**
     * Get accuracy percentage (100 - WER, capped at 0).
     */
    public function getAccuracy(): ?float
    {
        if ($this->wer === null) {
            return null;
        }

        return max(0, 100 - $this->wer);
    }

    /**
     * Convert to array for storage.
     */
    public function toArray(): array
    {
        return [
            'wer' => $this->wer,
            'cer' => $this->cer,
            'substitutions' => $this->substitutions,
            'insertions' => $this->insertions,
            'deletions' => $this->deletions,
            'reference_words' => $this->referenceWords,
            'hypothesis_words' => $this->hypothesisWords,
            'errors' => $this->errors,
        ];
    }

    /**
     * Create from array (e.g., from database).
     */
    public static function fromArray(array $data): self
    {
        return new self(
            wer: array_key_exists('wer', $data) ? $data['wer'] : null,
            cer: array_key_exists('cer', $data) ? $data['cer'] : null,
            substitutions: $data['substitutions'] ?? 0,
            insertions: $data['insertions'] ?? 0,
            deletions: $data['deletions'] ?? 0,
            referenceWords: $data['reference_words'] ?? 0,
            hypothesisWords: $data['hypothesis_words'] ?? 0,
            errors: $data['errors'] ?? [],
        );
    }
}
