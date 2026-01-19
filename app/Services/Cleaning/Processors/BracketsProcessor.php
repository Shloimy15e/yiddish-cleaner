<?php

namespace App\Services\Cleaning\Processors;

class BracketsProcessor implements ProcessorInterface
{
    public function process(string $text, ?array $context = null): ProcessorResult
    {
        $removals = [];
        $changesCount = 0;

        // Remove inline bracketed content [like this]
        // But keep full paragraphs that are entirely bracketed
        $lines = explode("\n", $text);
        $result = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // If the entire line is a bracketed note, keep it (don't remove full bracketed paragraphs)
            if (preg_match('/^\[.+\]$/', $trimmed)) {
                $result[] = $line;
                continue;
            }

            // Remove inline brackets
            $processed = preg_replace_callback(
                '/\[[^\]]+\]/',
                function ($matches) use (&$removals, &$changesCount) {
                    $content = $matches[0];
                    if (mb_strlen($content) < 100) {
                        $removals[] = $content;
                    } else {
                        $removals[] = mb_substr($content, 0, 50) . '...]';
                    }
                    $changesCount++;
                    return '';
                },
                $line
            );

            $result[] = $processed;
        }

        $text = implode("\n", $result);

        // Clean up any double spaces left behind
        $text = preg_replace('/  +/', ' ', $text);

        return new ProcessorResult($text, $removals, $changesCount);
    }

    public function getName(): string
    {
        return 'brackets_inline';
    }

    public function getDescription(): string
    {
        return 'Removes inline [bracketed] content, keeps full bracketed paragraphs';
    }
}
