<?php

namespace App\Services\Cleaning;

class CleaningResult
{
    public function __construct(
        public readonly string $originalText,
        public readonly string $cleanedText,
        public readonly array $removals,
        public readonly array $processorResults,
    ) {}

    public function getMetrics(): array
    {
        return [
            'original_chars' => mb_strlen($this->originalText),
            'cleaned_chars' => mb_strlen($this->cleanedText),
            'original_words' => $this->countWords($this->originalText),
            'cleaned_words' => $this->countWords($this->cleanedText),
            'word_count' => $this->countWords($this->cleanedText),
            'removals_count' => count($this->removals),
            'reduction_percent' => $this->getReductionPercent(),
        ];
    }

    protected function countWords(string $text): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }

        return count(preg_split('/\s+/u', $text));
    }

    public function getReductionPercent(): float
    {
        $original = mb_strlen($this->originalText);
        if ($original === 0) {
            return 0;
        }
        $cleaned = mb_strlen($this->cleanedText);

        return round((($original - $cleaned) / $original) * 100, 2);
    }

    public function getOriginalHash(): string
    {
        return hash('sha256', $this->originalText);
    }

    public function getCleanedHash(): string
    {
        return hash('sha256', $this->cleanedText);
    }
}
