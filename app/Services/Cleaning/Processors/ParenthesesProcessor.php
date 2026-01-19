<?php

namespace App\Services\Cleaning\Processors;

class ParenthesesProcessor implements ProcessorInterface
{
    public function process(string $text): ProcessorResult
    {
        $removals = [];
        $changesCount = 0;

        // Remove parenthetical notes that look like editorial additions
        // Keep parentheses that are part of the actual speech

        $text = preg_replace_callback(
            '/\(([^)]+)\)/',
            function ($matches) use (&$removals, &$changesCount) {
                $content = $matches[1];

                // Check if this looks like an editorial note
                if ($this->isEditorialNote($content)) {
                    if (mb_strlen($content) < 50) {
                        $removals[] = "({$content})";
                    } else {
                        $removals[] = '(' . mb_substr($content, 0, 47) . '...)';
                    }
                    $changesCount++;
                    return '';
                }

                // Keep it
                return $matches[0];
            },
            $text
        );

        // Clean up any double spaces left behind
        $text = preg_replace('/  +/', ' ', $text);

        return new ProcessorResult($text, $removals, $changesCount);
    }

    protected function isEditorialNote(string $content): bool
    {
        // Common editorial markers
        $editorialPatterns = [
            '/^הערה/u',           // Hebrew "note"
            '/^הגהה/u',           // Hebrew "correction"
            '/^note:/i',
            '/^ed\./i',
            '/^editor/i',
            '/^\d{1,2}:\d{2}/',   // Timestamps like 12:34
            '/^see\s/i',
            '/^cf\./i',
            '/^sic/i',
            '/^literally/i',
            '/^i\.e\./i',
            '/^unclear/i',
            '/^inaudible/i',
            '/^\?\?/',            // Question marks indicating uncertainty
        ];

        foreach ($editorialPatterns as $pattern) {
            if (preg_match($pattern, trim($content))) {
                return true;
            }
        }

        // Very short content in parentheses is often editorial
        if (mb_strlen($content) <= 3) {
            return true;
        }

        return false;
    }

    public function getName(): string
    {
        return 'parentheses';
    }

    public function getDescription(): string
    {
        return 'Removes editorial (parenthetical) notes, keeps speech parentheses';
    }
}
