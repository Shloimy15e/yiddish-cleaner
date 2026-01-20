<?php

namespace App\Services\Cleaning;

/**
 * Service for generating diffs between original and cleaned text.
 *
 * Provides line-by-line and word-by-word diff generation with
 * statistics about changes.
 */
class DiffService
{
    /**
     * Generate a line-by-line diff between original and cleaned text.
     *
     * @param  string  $original  The original text
     * @param  string  $cleaned  The cleaned/modified text
     * @return array Diff information with line-by-line changes
     */
    public function generateLineDiff(string $original, string $cleaned): array
    {
        $originalLines = explode("\n", $original);
        $cleanedLines = explode("\n", $cleaned);

        $changes = [];
        $stats = [
            'lines_removed' => 0,
            'lines_added' => 0,
            'lines_modified' => 0,
            'lines_unchanged' => 0,
        ];

        // Use Longest Common Subsequence approach
        $opcodes = $this->getOpcodes($originalLines, $cleanedLines);

        foreach ($opcodes as $opcode) {
            [$tag, $i1, $i2, $j1, $j2] = $opcode;

            switch ($tag) {
                case 'equal':
                    for ($idx = 0; $idx < ($i2 - $i1); $idx++) {
                        $line = $originalLines[$i1 + $idx] ?? '';
                        $changes[] = [
                            'type' => 'unchanged',
                            'original_line' => $i1 + $idx + 1,
                            'cleaned_line' => $j1 + $idx + 1,
                            'original' => $line,
                            'cleaned' => $line,
                        ];
                        $stats['lines_unchanged']++;
                    }
                    break;

                case 'replace':
                    $origChunk = array_slice($originalLines, $i1, $i2 - $i1);
                    $cleanChunk = array_slice($cleanedLines, $j1, $j2 - $j1);
                    $maxLen = max(count($origChunk), count($cleanChunk));

                    for ($idx = 0; $idx < $maxLen; $idx++) {
                        $origLine = $origChunk[$idx] ?? null;
                        $cleanLine = $cleanChunk[$idx] ?? null;

                        if ($origLine !== null && $cleanLine !== null) {
                            // Both exist - it's a modification
                            $wordDiff = $this->generateWordDiff($origLine, $cleanLine);
                            $changes[] = [
                                'type' => 'modified',
                                'original_line' => $i1 + $idx + 1,
                                'cleaned_line' => $j1 + $idx + 1,
                                'original' => $origLine,
                                'cleaned' => $cleanLine,
                                'word_diff' => $wordDiff,
                            ];
                            $stats['lines_modified']++;
                        } elseif ($origLine !== null) {
                            // Only original exists - it was removed
                            $changes[] = [
                                'type' => 'removed',
                                'original_line' => $i1 + $idx + 1,
                                'cleaned_line' => null,
                                'original' => $origLine,
                                'cleaned' => null,
                            ];
                            $stats['lines_removed']++;
                        } else {
                            // Only cleaned exists - it was added
                            $changes[] = [
                                'type' => 'added',
                                'original_line' => null,
                                'cleaned_line' => $j1 + $idx + 1,
                                'original' => null,
                                'cleaned' => $cleanLine,
                            ];
                            $stats['lines_added']++;
                        }
                    }
                    break;

                case 'delete':
                    for ($idx = $i1; $idx < $i2; $idx++) {
                        $changes[] = [
                            'type' => 'removed',
                            'original_line' => $idx + 1,
                            'cleaned_line' => null,
                            'original' => $originalLines[$idx] ?? '',
                            'cleaned' => null,
                        ];
                        $stats['lines_removed']++;
                    }
                    break;

                case 'insert':
                    for ($idx = $j1; $idx < $j2; $idx++) {
                        $changes[] = [
                            'type' => 'added',
                            'original_line' => null,
                            'cleaned_line' => $idx + 1,
                            'original' => null,
                            'cleaned' => $cleanedLines[$idx] ?? '',
                        ];
                        $stats['lines_added']++;
                    }
                    break;
            }
        }

        return [
            'changes' => $changes,
            'stats' => $stats,
        ];
    }

    /**
     * Generate word-level diff for a single line.
     */
    public function generateWordDiff(string $original, string $cleaned): array
    {
        $originalWords = $this->splitIntoWords($original);
        $cleanedWords = $this->splitIntoWords($cleaned);

        $opcodes = $this->getOpcodes($originalWords, $cleanedWords);
        $result = [];

        foreach ($opcodes as $opcode) {
            [$tag, $i1, $i2, $j1, $j2] = $opcode;

            switch ($tag) {
                case 'equal':
                    for ($idx = $i1; $idx < $i2; $idx++) {
                        $result[] = [
                            'type' => 'unchanged',
                            'text' => $originalWords[$idx],
                        ];
                    }
                    break;

                case 'replace':
                    // Show removed words
                    for ($idx = $i1; $idx < $i2; $idx++) {
                        $result[] = [
                            'type' => 'removed',
                            'text' => $originalWords[$idx],
                        ];
                    }
                    // Show added words
                    for ($idx = $j1; $idx < $j2; $idx++) {
                        $result[] = [
                            'type' => 'added',
                            'text' => $cleanedWords[$idx],
                        ];
                    }
                    break;

                case 'delete':
                    for ($idx = $i1; $idx < $i2; $idx++) {
                        $result[] = [
                            'type' => 'removed',
                            'text' => $originalWords[$idx],
                        ];
                    }
                    break;

                case 'insert':
                    for ($idx = $j1; $idx < $j2; $idx++) {
                        $result[] = [
                            'type' => 'added',
                            'text' => $cleanedWords[$idx],
                        ];
                    }
                    break;
            }
        }

        return $result;
    }

    /**
     * Get diff summary statistics.
     */
    public function getDiffSummary(string $original, string $cleaned): array
    {
        $originalLines = count(explode("\n", $original));
        $cleanedLines = count(explode("\n", $cleaned));

        $originalChars = mb_strlen($original);
        $cleanedChars = mb_strlen($cleaned);

        $originalWords = count(preg_split('/\s+/', $original, -1, PREG_SPLIT_NO_EMPTY));
        $cleanedWords = count(preg_split('/\s+/', $cleaned, -1, PREG_SPLIT_NO_EMPTY));

        $similarity = $this->calculateSimilarity($original, $cleaned);

        return [
            'original_lines' => $originalLines,
            'cleaned_lines' => $cleanedLines,
            'lines_diff' => $cleanedLines - $originalLines,
            'original_chars' => $originalChars,
            'cleaned_chars' => $cleanedChars,
            'chars_diff' => $cleanedChars - $originalChars,
            'original_words' => $originalWords,
            'cleaned_words' => $cleanedWords,
            'words_diff' => $cleanedWords - $originalWords,
            'similarity_percent' => $similarity,
            'reduction_percent' => $originalChars > 0
                ? round((1 - $cleanedChars / $originalChars) * 100, 2)
                : 0,
        ];
    }

    /**
     * Calculate similarity percentage between two strings.
     */
    protected function calculateSimilarity(string $original, string $cleaned): float
    {
        if (empty($original) && empty($cleaned)) {
            return 100.0;
        }

        $maxLen = max(mb_strlen($original), mb_strlen($cleaned));
        if ($maxLen === 0) {
            return 100.0;
        }

        $distance = levenshtein(
            mb_substr($original, 0, 255),
            mb_substr($cleaned, 0, 255)
        );

        $compareLen = min(255, $maxLen);
        $similarity = (1 - $distance / $compareLen) * 100;

        return round(max(0, $similarity), 2);
    }

    /**
     * Split text into words while preserving whitespace context.
     */
    protected function splitIntoWords(string $text): array
    {
        return preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    }

    /**
     * Get operation codes for transforming seq1 to seq2.
     * Returns array of [tag, i1, i2, j1, j2] tuples.
     */
    protected function getOpcodes(array $seq1, array $seq2): array
    {
        $lcs = $this->longestCommonSubsequence($seq1, $seq2);
        $opcodes = [];

        $i = 0;
        $j = 0;
        $lcsIdx = 0;
        $lcsLen = count($lcs);

        while ($i < count($seq1) || $j < count($seq2)) {
            // Find next match
            $matchI = null;
            $matchJ = null;

            if ($lcsIdx < $lcsLen) {
                [$matchI, $matchJ] = $lcs[$lcsIdx];
            }

            // Handle deletions/insertions/replacements before match
            if ($matchI !== null) {
                if ($i < $matchI && $j < $matchJ) {
                    $opcodes[] = ['replace', $i, $matchI, $j, $matchJ];
                    $i = $matchI;
                    $j = $matchJ;
                } elseif ($i < $matchI) {
                    $opcodes[] = ['delete', $i, $matchI, $j, $j];
                    $i = $matchI;
                } elseif ($j < $matchJ) {
                    $opcodes[] = ['insert', $i, $i, $j, $matchJ];
                    $j = $matchJ;
                }

                // Handle the match itself
                $equalEnd = $matchI + 1;
                $lcsIdx++;

                // Extend equal region if consecutive matches
                while ($lcsIdx < $lcsLen) {
                    [$nextI, $nextJ] = $lcs[$lcsIdx];
                    if ($nextI === $equalEnd && $nextJ === $j + ($equalEnd - $matchI)) {
                        $equalEnd++;
                        $lcsIdx++;
                    } else {
                        break;
                    }
                }

                $equalLen = $equalEnd - $matchI;
                $opcodes[] = ['equal', $matchI, $equalEnd, $matchJ, $matchJ + $equalLen];
                $i = $equalEnd;
                $j = $matchJ + $equalLen;
            } else {
                // No more matches
                if ($i < count($seq1) && $j < count($seq2)) {
                    $opcodes[] = ['replace', $i, count($seq1), $j, count($seq2)];
                } elseif ($i < count($seq1)) {
                    $opcodes[] = ['delete', $i, count($seq1), $j, $j];
                } elseif ($j < count($seq2)) {
                    $opcodes[] = ['insert', $i, $i, $j, count($seq2)];
                }
                break;
            }
        }

        return $opcodes;
    }

    /**
     * Find longest common subsequence between two arrays.
     * Returns array of [i, j] pairs indicating matching positions.
     */
    protected function longestCommonSubsequence(array $seq1, array $seq2): array
    {
        $m = count($seq1);
        $n = count($seq2);

        if ($m === 0 || $n === 0) {
            return [];
        }

        // Build LCS table
        $lcs = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));

        for ($i = 1; $i <= $m; $i++) {
            for ($j = 1; $j <= $n; $j++) {
                if ($seq1[$i - 1] === $seq2[$j - 1]) {
                    $lcs[$i][$j] = $lcs[$i - 1][$j - 1] + 1;
                } else {
                    $lcs[$i][$j] = max($lcs[$i - 1][$j], $lcs[$i][$j - 1]);
                }
            }
        }

        // Backtrack to find the actual LCS
        $result = [];
        $i = $m;
        $j = $n;

        while ($i > 0 && $j > 0) {
            if ($seq1[$i - 1] === $seq2[$j - 1]) {
                array_unshift($result, [$i - 1, $j - 1]);
                $i--;
                $j--;
            } elseif ($lcs[$i - 1][$j] > $lcs[$i][$j - 1]) {
                $i--;
            } else {
                $j--;
            }
        }

        return $result;
    }
}
