<?php

namespace App\Services\Cleaning\Processors;

/**
 * Processor that removes paragraphs based on Word document styling:
 * - Word heading styles (Heading 1, Heading 2, Title, etc.)
 * - Short paragraphs (less than min_words)
 * - Text larger than normal body text
 * - Bold titles without sentence-ending punctuation
 */
class TitleStyleProcessor implements ProcessorInterface
{
    protected int $minWords = 5;

    protected float $sizeThreshold = 1.2;

    protected array $exceptionPatterns = [];

    protected array $forceRemovePatterns = [];

    public function __construct()
    {
        $this->exceptionPatterns = config('cleaning.exception_patterns', []);
        $this->forceRemovePatterns = config('cleaning.force_remove_patterns', []);
    }

    public function process(string $text, ?array $context = null): ProcessorResult
    {
        // If we have paragraph metadata, use context-aware processing
        if ($context && isset($context['paragraphs']) && ! empty($context['paragraphs'])) {
            return $this->processWithContext($text, $context);
        }

        // Fallback to basic heuristic processing
        return $this->processBasic($text);
    }

    /**
     * Process using rich Word document metadata.
     */
    protected function processWithContext(string $text, array $context): ProcessorResult
    {
        $paragraphsMeta = $context['paragraphs'];
        $removals = [];
        $keptParagraphs = [];
        $changesCount = 0;

        foreach ($paragraphsMeta as $meta) {
            $paraText = $meta['text'] ?? '';
            $trimmed = trim($paraText);

            if (empty($trimmed)) {
                continue;
            }

            // Check force remove patterns first
            if ($this->matchesForceRemove($trimmed)) {
                $removals[] = $this->formatRemoval($trimmed, 'Force removed (blocked pattern)');
                $changesCount++;

                continue;
            }

            // Check exception patterns - if matches, always keep
            if ($this->matchesException($trimmed)) {
                $keptParagraphs[] = $paraText;

                continue;
            }

            $shouldRemove = false;
            $reason = '';

            // 1. Check Word heading styles
            if ($meta['is_heading_style'] ?? false) {
                $shouldRemove = true;
                $reason = 'Word heading style: '.($meta['style_name'] ?? 'unknown');
            }
            // 2. Check if larger than normal text
            elseif ($meta['is_larger_than_normal'] ?? false) {
                $shouldRemove = true;
                $reason = sprintf(
                    'Large font (%.1fpt > avg %.1fpt)',
                    $meta['font_size'] ?? 0,
                    $meta['avg_font_size'] ?? 12
                );
            }
            // 3. Check if short paragraph (likely a title/heading)
            elseif (($meta['word_count'] ?? 0) < $this->minWords) {
                // Only remove short paragraphs that look like titles
                if ($this->looksLikeTitle($trimmed, $meta)) {
                    $shouldRemove = true;
                    $reason = sprintf('Short paragraph (%d words)', $meta['word_count'] ?? 0);
                }
            }
            // 4. Check if bold paragraph without ending punctuation
            elseif (($meta['is_bold'] ?? false) && ! $this->hasSentenceEnding($trimmed)) {
                $wordCount = $meta['word_count'] ?? count(preg_split('/\s+/', $trimmed));
                if ($wordCount <= 10) {
                    $shouldRemove = true;
                    $reason = 'Bold paragraph without sentence ending';
                }
            }

            if ($shouldRemove) {
                $removals[] = $this->formatRemoval($trimmed, $reason);
                $changesCount++;
            } else {
                $keptParagraphs[] = $paraText;
            }
        }

        $cleanedText = implode("\n", $keptParagraphs);

        return new ProcessorResult($cleanedText, $removals, $changesCount);
    }

    /**
     * Basic heuristic processing when no context is available.
     */
    protected function processBasic(string $text): ProcessorResult
    {
        $lines = explode("\n", $text);
        $removals = [];
        $changesCount = 0;
        $result = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                $result[] = $line;

                continue;
            }

            // Check force remove patterns
            if ($this->matchesForceRemove($trimmed)) {
                $removals[] = $this->formatRemoval($trimmed, 'Force removed');
                $changesCount++;

                continue;
            }

            // Check exception patterns
            if ($this->matchesException($trimmed)) {
                $result[] = $line;

                continue;
            }

            if ($this->isTitleHeuristic($trimmed)) {
                $removals[] = $this->formatRemoval($trimmed, 'Title pattern');
                $changesCount++;

                continue;
            }

            $result[] = $line;
        }

        return new ProcessorResult(implode("\n", $result), $removals, $changesCount);
    }

    /**
     * Check if text looks like a title using various indicators.
     */
    protected function looksLikeTitle(string $text, array $meta): bool
    {
        // Ends with common title markers (colon)
        if (preg_match('/[:׃]\s*$/', $text)) {
            return true;
        }

        // No sentence-ending punctuation
        if (! $this->hasSentenceEnding($text)) {
            return true;
        }

        // All caps (for Latin text)
        if (preg_match('/^[A-Z\s\d]+$/', $text) && mb_strlen($text) > 5) {
            return true;
        }

        // Is bold
        if ($meta['is_bold'] ?? false) {
            return true;
        }

        return false;
    }

    /**
     * Basic title detection heuristic.
     */
    protected function isTitleHeuristic(string $line): bool
    {
        $length = mb_strlen($line);

        // Too long for a title
        if ($length > 100) {
            return false;
        }

        // Too short to analyze
        if ($length < 3) {
            return false;
        }

        // Ends with Hebrew/English colon
        if (preg_match('/[:׃]\s*$/', $line)) {
            return true;
        }

        // All caps
        if (preg_match('/^[A-Z\s\d]+$/', $line) && $length > 5) {
            return true;
        }

        // Short line without sentence-ending punctuation
        if ($length < 50 && ! $this->hasSentenceEnding($line)) {
            $wordCount = count(preg_split('/\s+/', $line));
            if ($wordCount <= 5) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if text has sentence-ending punctuation.
     */
    protected function hasSentenceEnding(string $text): bool
    {
        return (bool) preg_match('/[.!?。؟]$/', trim($text));
    }

    /**
     * Check if a pattern appears to be a literal string (not a regex).
     * Literal patterns don't contain regex metacharacters.
     */
    protected function isLiteralPattern(string $pattern): bool
    {
        // If it contains regex metacharacters that are likely intentional, treat as regex
        $regexIndicators = ['.*', '.+', '^', '$', '\\d', '\\w', '\\s', '[', '(', '|', '\\b'];

        foreach ($regexIndicators as $indicator) {
            if (str_contains($pattern, $indicator)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if text matches any exception pattern.
     */
    protected function matchesException(string $text): bool
    {
        foreach ($this->exceptionPatterns as $pattern) {
            $regexPattern = $this->isLiteralPattern($pattern)
                ? '/'.preg_quote($pattern, '/').'/u'
                : "/{$pattern}/u";

            if (@preg_match($regexPattern, $text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if text matches any force remove pattern.
     */
    protected function matchesForceRemove(string $text): bool
    {
        foreach ($this->forceRemovePatterns as $pattern) {
            $regexPattern = $this->isLiteralPattern($pattern)
                ? '/'.preg_quote($pattern, '/').'/u'
                : "/{$pattern}/u";

            if (@preg_match($regexPattern, $text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Format a removal entry.
     */
    protected function formatRemoval(string $text, string $reason): array
    {
        return [
            'text' => mb_strlen($text) > 60 ? mb_substr($text, 0, 60).'...' : $text,
            'full_text' => $text,
            'reason' => $reason,
            'processor' => $this->getName(),
        ];
    }

    public function getName(): string
    {
        return 'title_style';
    }

    public function getDescription(): string
    {
        return 'Removes titles based on Word styles, size, and paragraph length';
    }
}
