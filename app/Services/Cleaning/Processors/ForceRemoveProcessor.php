<?php

namespace App\Services\Cleaning\Processors;

/**
 * Processor that removes content matching configured force-remove patterns.
 *
 * Force remove patterns are user-configured regex patterns for content
 * that should always be removed regardless of other processing rules.
 */
class ForceRemoveProcessor implements ProcessorInterface
{
    protected array $forceRemovePatterns = [];

    public function __construct()
    {
        $this->forceRemovePatterns = config('cleaning.force_remove_patterns', []);
    }

    public function process(string $text, ?array $context = null): ProcessorResult
    {
        if (empty($this->forceRemovePatterns)) {
            return ProcessorResult::unchanged($text);
        }

        $removals = [];
        $changesCount = 0;
        $lines = explode("\n", $text);
        $result = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (empty($trimmed)) {
                $result[] = $line;

                continue;
            }

            $matchedPattern = $this->matchesForceRemove($trimmed);

            if ($matchedPattern !== null) {
                $removals[] = [
                    'text' => mb_strlen($trimmed) > 60 ? mb_substr($trimmed, 0, 60).'...' : $trimmed,
                    'full_text' => $trimmed,
                    'reason' => "Force removed (matched: {$matchedPattern})",
                    'processor' => $this->getName(),
                ];
                $changesCount++;

                continue;
            }

            // Also check for inline matches and remove them
            $processedLine = $this->removeInlinePatterns($line, $removals, $changesCount);
            $result[] = $processedLine;
        }

        $cleanedText = implode("\n", $result);

        return new ProcessorResult($cleanedText, $removals, $changesCount);
    }

    /**
     * Check if text matches any force remove pattern.
     *
     * @return string|null The matched pattern, or null if no match
     */
    protected function matchesForceRemove(string $text): ?string
    {
        foreach ($this->forceRemovePatterns as $pattern) {
            // Escape special chars if pattern looks like a literal string
            $regexPattern = $this->isLiteralPattern($pattern)
                ? '/'.preg_quote($pattern, '/').'/u'
                : "/{$pattern}/u";

            if (@preg_match($regexPattern, $text)) {
                return $pattern;
            }
        }

        return null;
    }

    /**
     * Remove inline occurrences of force-remove patterns.
     */
    protected function removeInlinePatterns(string $line, array &$removals, int &$changesCount): string
    {
        $processedLine = $line;

        foreach ($this->forceRemovePatterns as $pattern) {
            $regexPattern = $this->isLiteralPattern($pattern)
                ? '/'.preg_quote($pattern, '/').'/u'
                : "/{$pattern}/u";

            $count = 0;
            $newLine = @preg_replace($regexPattern, '', $processedLine, -1, $count);

            if ($count > 0 && $newLine !== null) {
                $processedLine = $newLine;
                $changesCount += $count;
            }
        }

        // Clean up double spaces
        $processedLine = preg_replace('/  +/', ' ', $processedLine);

        return $processedLine;
    }

    /**
     * Check if a pattern appears to be a literal string (not a regex).
     */
    protected function isLiteralPattern(string $pattern): bool
    {
        // If it contains regex metacharacters that are likely intentional, treat as regex
        $regexIndicators = ['.*', '.+', '^', '$', '\\d', '\\w', '\\s', '[', '(', '|'];

        foreach ($regexIndicators as $indicator) {
            if (str_contains($pattern, $indicator)) {
                return false;
            }
        }

        return true;
    }

    public function getName(): string
    {
        return 'force_remove';
    }

    public function getDescription(): string
    {
        return 'Removes content matching configured force-remove patterns';
    }
}
