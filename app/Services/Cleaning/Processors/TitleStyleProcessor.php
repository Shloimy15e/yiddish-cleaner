<?php

namespace App\Services\Cleaning\Processors;

class TitleStyleProcessor implements ProcessorInterface
{
    protected int $minTitleLength = 3;
    protected int $maxTitleLength = 100;

    public function process(string $text): ProcessorResult
    {
        $lines = explode("\n", $text);
        $removals = [];
        $changesCount = 0;
        $result = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Skip empty lines
            if ($trimmed === '') {
                $result[] = $line;
                continue;
            }

            // Check if line looks like a title/heading
            if ($this->isTitle($trimmed)) {
                $removals[] = mb_substr($trimmed, 0, 50) . (mb_strlen($trimmed) > 50 ? '...' : '');
                $changesCount++;
                continue;
            }

            $result[] = $line;
        }

        return new ProcessorResult(implode("\n", $result), $removals, $changesCount);
    }

    protected function isTitle(string $line): bool
    {
        $length = mb_strlen($line);

        // Too short or too long for a title
        if ($length < $this->minTitleLength || $length > $this->maxTitleLength) {
            return false;
        }

        // Ends with common title markers
        if (preg_match('/[:׃]\s*$/', $line)) {
            return true;
        }

        // All caps or mostly caps (for Latin)
        if (preg_match('/^[A-Z\s\d]+$/', $line) && $length > 5) {
            return true;
        }

        // Short line without sentence-ending punctuation (likely a heading)
        if ($length < 50 && !preg_match('/[.!?]$/', $line)) {
            // Check if it's a standalone short phrase
            $wordCount = count(preg_split('/\s+/', $line));
            if ($wordCount <= 5) {
                return true;
            }
        }

        return false;
    }

    public function getName(): string
    {
        return 'title_style';
    }

    public function getDescription(): string
    {
        return 'Removes title/heading lines based on style patterns';
    }
}
