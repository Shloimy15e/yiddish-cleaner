<?php

namespace App\Services\Cleaning\Processors;

class SeifMarkerProcessor implements ProcessorInterface
{
    public function process(string $text, ?array $context = null): ProcessorResult
    {
        $removals = [];
        $changesCount = 0;

        // Remove Hebrew letter markers (א', ב', etc.) at start of paragraphs
        $patterns = [
            // Hebrew letter with geresh/apostrophe at line start
            '/^[\u{05D0}-\u{05EA}][\'׳]\s*/mu' => 'Hebrew letter marker',
            // Parenthesized Hebrew letter at line start
            '/^\([\u{05D0}-\u{05EA}]\)\s*/mu' => 'Parenthesized Hebrew marker',
            // Seif numbers like "סעיף א" or "ס' א"
            '/^ס(?:עיף|\')\s*[\u{05D0}-\u{05EA}][\'׳]?\s*/mu' => 'Seif marker',
            // Numeric markers at line start
            '/^\d+[\.\)]\s*/m' => 'Numeric marker',
        ];

        foreach ($patterns as $pattern => $name) {
            $count = 0;
            $text = preg_replace($pattern, '', $text, -1, $count);
            if ($count > 0) {
                $removals[] = "{$count}x {$name}";
                $changesCount += $count;
            }
        }

        return new ProcessorResult($text, $removals, $changesCount);
    }

    public function getName(): string
    {
        return 'seif_marker';
    }

    public function getDescription(): string
    {
        return 'Removes seif/paragraph markers (Hebrew letters, numbers)';
    }
}
