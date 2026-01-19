<?php

namespace App\Services\Cleaning\Processors;

class SpecialCharsProcessor implements ProcessorInterface
{
    public function process(string $text, ?array $context = null): ProcessorResult
    {
        $removals = [];
        $changesCount = 0;

        // Remove zero-width characters
        $patterns = [
            '/\x{200B}/u' => 'zero-width space',
            '/\x{200C}/u' => 'zero-width non-joiner',
            '/\x{200D}/u' => 'zero-width joiner',
            '/\x{FEFF}/u' => 'BOM',
            '/\x{00AD}/u' => 'soft hyphen',
        ];

        foreach ($patterns as $pattern => $name) {
            $count = 0;
            $text = preg_replace($pattern, '', $text, -1, $count);
            if ($count > 0) {
                $removals[] = "{$count}x {$name}";
                $changesCount += $count;
            }
        }

        // Normalize quotes (using Unicode escape sequences for compatibility)
        $text = str_replace(["\u{201C}", "\u{201D}", "\u{201E}"], '"', $text); // " " „
        $text = str_replace(["\u{2018}", "\u{2019}"], "'", $text); // ' '

        // Normalize dashes
        $text = str_replace(["\u{2013}", "\u{2014}"], '-', $text); // – —

        return new ProcessorResult($text, $removals, $changesCount);
    }

    public function getName(): string
    {
        return 'special_chars';
    }

    public function getDescription(): string
    {
        return 'Removes zero-width characters, normalizes quotes and dashes';
    }
}
