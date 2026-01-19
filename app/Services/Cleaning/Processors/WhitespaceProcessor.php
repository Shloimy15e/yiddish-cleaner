<?php

namespace App\Services\Cleaning\Processors;

class WhitespaceProcessor implements ProcessorInterface
{
    public function process(string $text, ?array $context = null): ProcessorResult
    {
        $original = $text;

        // Normalize line endings
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Remove trailing whitespace from each line
        $text = preg_replace('/[ \t]+$/m', '', $text);

        // Collapse multiple blank lines to max 2
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        // Collapse multiple spaces to single space
        $text = preg_replace('/[ \t]+/', ' ', $text);

        // Trim leading/trailing whitespace
        $text = trim($text);

        $changes = $original !== $text ? 1 : 0;

        return new ProcessorResult($text, [], $changes);
    }

    public function getName(): string
    {
        return 'whitespace';
    }

    public function getDescription(): string
    {
        return 'Normalizes whitespace, collapses multiple blank lines';
    }
}
