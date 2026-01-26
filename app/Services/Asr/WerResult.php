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
        // Range used for calculation (word indices, 0-based, inclusive)
        public readonly ?int $refStart = null,
        public readonly ?int $refEnd = null,
        public readonly ?int $hypStart = null,
        public readonly ?int $hypEnd = null,
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
            'wer_ref_start' => $this->refStart,
            'wer_ref_end' => $this->refEnd,
            'wer_hyp_start' => $this->hypStart,
            'wer_hyp_end' => $this->hypEnd,
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
            refStart: $data['wer_ref_start'] ?? null,
            refEnd: $data['wer_ref_end'] ?? null,
            hypStart: $data['wer_hyp_start'] ?? null,
            hypEnd: $data['wer_hyp_end'] ?? null,
        );
    }
}
