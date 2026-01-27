<?php

namespace App\Services\Asr;

/**
 * Word Error Rate (WER) and Character Error Rate (CER) Calculator.
 *
 * Uses Levenshtein distance at word and character level to compute
 * substitutions, insertions, deletions, and error rates.
 */
class WerCalculator
{
    protected const MAX_MATRIX_CELLS = 20000;

    protected const MAX_WORD_CELLS = 250000;

    /**
     * Calculate WER and CER metrics between reference and hypothesis texts.
     *
     * @param  string  $reference  The reference (ground truth) text
     * @param  string  $hypothesis  The hypothesis (ASR output) text
     * @param  int|null  $refStart  Start word index for reference (0-based, inclusive)
     * @param  int|null  $refEnd  End word index for reference (0-based, inclusive)
     * @param  int|null  $hypStart  Start word index for hypothesis (0-based, inclusive)
     * @param  int|null  $hypEnd  End word index for hypothesis (0-based, inclusive)
     */
    public function calculate(
        string $reference,
        string $hypothesis,
        ?int $refStart = null,
        ?int $refEnd = null,
        ?int $hypStart = null,
        ?int $hypEnd = null,
    ): WerResult {
        // Normalize texts
        $reference = $this->normalize($reference);
        $hypothesis = $this->normalize($hypothesis);

        // Calculate word-level metrics (WER)
        $refWords = $this->tokenizeWords($reference);
        $hypWords = $this->tokenizeWords($hypothesis);

        // Apply range slicing if specified
        $totalRefWords = count($refWords);
        $totalHypWords = count($hypWords);

        // Clamp and apply reference range
        if ($refStart !== null || $refEnd !== null) {
            $refStart = $refStart ?? 0;
            $refEnd = $refEnd ?? ($totalRefWords - 1);
            $refStart = max(0, min($refStart, $totalRefWords - 1));
            $refEnd = max($refStart, min($refEnd, $totalRefWords - 1));
            $refWords = array_slice($refWords, $refStart, $refEnd - $refStart + 1);
        } else {
            $refStart = 0;
            $refEnd = $totalRefWords > 0 ? $totalRefWords - 1 : null;
        }

        // Clamp and apply hypothesis range
        if ($hypStart !== null || $hypEnd !== null) {
            $hypStart = $hypStart ?? 0;
            $hypEnd = $hypEnd ?? ($totalHypWords - 1);
            $hypStart = max(0, min($hypStart, $totalHypWords - 1));
            $hypEnd = max($hypStart, min($hypEnd, $totalHypWords - 1));
            $hypWords = array_slice($hypWords, $hypStart, $hypEnd - $hypStart + 1);
        } else {
            $hypStart = 0;
            $hypEnd = $totalHypWords > 0 ? $totalHypWords - 1 : null;
        }

        $wordCells = count($refWords) * count($hypWords);

        if ($wordCells > self::MAX_WORD_CELLS) {
            $wordMetrics = $this->levenshteinCountsLinear($refWords, $hypWords);
        } else {
            $wordMetrics = $this->shouldUseLinear($refWords, $hypWords)
                ? $this->levenshteinCountsLinear($refWords, $hypWords)
                : $this->levenshteinDetails($refWords, $hypWords);
        }

        $wer = count($refWords) > 0
            ? ($wordMetrics['substitutions'] + $wordMetrics['insertions'] + $wordMetrics['deletions']) / count($refWords) * 100
            : 0.0;

        // Calculate character-level metrics (CER) - uses full sliced text
        $refText = implode(' ', $refWords);
        $hypText = implode(' ', $hypWords);
        $refChars = $this->tokenizeChars($refText);
        $hypChars = $this->tokenizeChars($hypText);
        $charMetrics = $this->levenshteinCountsLinear($refChars, $hypChars);
        $cer = count($refChars) > 0
            ? ($charMetrics['substitutions'] + $charMetrics['insertions'] + $charMetrics['deletions']) / count($refChars) * 100
            : 0.0;

        return new WerResult(
            wer: round($wer, 2),
            cer: round($cer, 2),
            substitutions: $wordMetrics['substitutions'],
            insertions: $wordMetrics['insertions'],
            deletions: $wordMetrics['deletions'],
            referenceWords: count($refWords),
            hypothesisWords: count($hypWords),
            errors: $wordMetrics['errors'] ?? [],
            refStart: $refStart,
            refEnd: $refEnd,
            hypStart: $hypStart,
            hypEnd: $hypEnd,
        );
    }

    protected function shouldUseLinear(array $ref, array $hyp): bool
    {
        $m = count($ref);
        $n = count($hyp);

        return $m === 0 || $n === 0 || ($m * $n) > self::MAX_MATRIX_CELLS;
    }

    /**
     * Normalize text for comparison.
     *
     * Strips:
     * - Hebrew/Yiddish nikkud (vowel points): U+05B0-U+05BD, U+05BF, U+05C1-U+05C2, U+05C4-U+05C5, U+05C7
     * - Hebrew cantillation marks (trop): U+0591-U+05AF
     * - Other combining marks
     * - Punctuation and symbols
     * - Excess whitespace
     */
    protected function normalize(string $text): string
    {
        // Convert to lowercase
        $text = mb_strtolower($text, 'UTF-8');

        // Remove Hebrew nikkud (vowel points) - U+05B0 to U+05BD, U+05BF, U+05C1-U+05C2, U+05C4-U+05C5, U+05C7
        $text = preg_replace('/[\x{05B0}-\x{05BD}\x{05BF}\x{05C1}\x{05C2}\x{05C4}\x{05C5}\x{05C7}]/u', '', $text);

        // Remove Hebrew cantillation marks (trop/teamim) - U+0591 to U+05AF
        $text = preg_replace('/[\x{0591}-\x{05AF}]/u', '', $text);

        // Remove other combining diacritical marks
        $text = preg_replace('/\p{M}/u', '', $text);

        // Remove punctuation and symbols but keep Hebrew/Yiddish letters and numbers
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', '', $text);

        // Normalize whitespace
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /**
     * Tokenize text into words.
     */
    protected function tokenizeWords(string $text): array
    {
        if (empty($text)) {
            return [];
        }

        return preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    }

    /**
     * Tokenize text into characters (for CER).
     */
    protected function tokenizeChars(string $text): array
    {
        if (empty($text)) {
            return [];
        }

        // Remove spaces for character-level comparison
        $text = preg_replace('/\s+/', '', $text);

        $utf32 = mb_convert_encoding($text, 'UTF-32LE', 'UTF-8');
        if ($utf32 === '' || $utf32 === false) {
            return [];
        }

        return array_values(unpack('V*', $utf32));
    }

    /**
     * Calculate Levenshtein distance with detailed error breakdown.
     *
     * Returns substitutions, insertions, deletions, and optionally error details.
     */
    protected function levenshteinDetails(array $ref, array $hyp): array
    {
        $m = count($ref);
        $n = count($hyp);

        // Create distance matrix
        $d = [];
        for ($i = 0; $i <= $m; $i++) {
            $d[$i] = [];
            for ($j = 0; $j <= $n; $j++) {
                $d[$i][$j] = 0;
            }
        }

        // Initialize first column
        for ($i = 0; $i <= $m; $i++) {
            $d[$i][0] = $i;
        }

        // Initialize first row
        for ($j = 0; $j <= $n; $j++) {
            $d[0][$j] = $j;
        }

        // Fill in the rest of the matrix
        for ($i = 1; $i <= $m; $i++) {
            for ($j = 1; $j <= $n; $j++) {
                $cost = ($ref[$i - 1] === $hyp[$j - 1]) ? 0 : 1;

                $d[$i][$j] = min(
                    $d[$i - 1][$j] + 1,      // Deletion
                    $d[$i][$j - 1] + 1,      // Insertion
                    $d[$i - 1][$j - 1] + $cost // Substitution
                );
            }
        }

        // Backtrace to find specific errors
        $substitutions = 0;
        $insertions = 0;
        $deletions = 0;
        $errors = [];

        $i = $m;
        $j = $n;

        while ($i > 0 || $j > 0) {
            if ($i > 0 && $j > 0 && $ref[$i - 1] === $hyp[$j - 1]) {
                // Match - no error
                $i--;
                $j--;
            } elseif ($i > 0 && $j > 0 && $d[$i][$j] === $d[$i - 1][$j - 1] + 1) {
                // Substitution
                $substitutions++;
                $errors[] = [
                    'type' => 'substitution',
                    'ref' => $ref[$i - 1],
                    'hyp' => $hyp[$j - 1],
                    'position' => $i - 1,
                ];
                $i--;
                $j--;
            } elseif ($j > 0 && $d[$i][$j] === $d[$i][$j - 1] + 1) {
                // Insertion (extra word in hypothesis)
                $insertions++;
                $errors[] = [
                    'type' => 'insertion',
                    'hyp' => $hyp[$j - 1],
                    'position' => $j - 1,
                ];
                $j--;
            } else {
                // Deletion (word missing from hypothesis)
                $deletions++;
                $errors[] = [
                    'type' => 'deletion',
                    'ref' => $ref[$i - 1],
                    'position' => $i - 1,
                ];
                $i--;
            }
        }

        return [
            'substitutions' => $substitutions,
            'insertions' => $insertions,
            'deletions' => $deletions,
            'errors' => array_reverse($errors),
        ];
    }

    /**
     * Linear-memory Levenshtein with counts (no error list).
     */
    protected function levenshteinCountsLinear(array $ref, array $hyp): array
    {
        $m = count($ref);
        $n = count($hyp);

        if ($m === 0) {
            return [
                'substitutions' => 0,
                'insertions' => $n,
                'deletions' => 0,
                'errors' => [],
                'distance' => $n,
            ];
        }

        if ($n === 0) {
            return [
                'substitutions' => 0,
                'insertions' => 0,
                'deletions' => $m,
                'errors' => [],
                'distance' => $m,
            ];
        }

        $prevDist = range(0, $n);
        $prevIns = array_fill(0, $n + 1, 0);
        $prevDel = array_fill(0, $n + 1, 0);
        $prevSub = array_fill(0, $n + 1, 0);

        for ($j = 1; $j <= $n; $j++) {
            $prevIns[$j] = $j;
        }

        for ($i = 1; $i <= $m; $i++) {
            $currDist = array_fill(0, $n + 1, 0);
            $currIns = array_fill(0, $n + 1, 0);
            $currDel = array_fill(0, $n + 1, 0);
            $currSub = array_fill(0, $n + 1, 0);

            $currDist[0] = $i;
            $currDel[0] = $i;

            for ($j = 1; $j <= $n; $j++) {
                $cost = ($ref[$i - 1] === $hyp[$j - 1]) ? 0 : 1;

                $delDist = $prevDist[$j] + 1;
                $insDist = $currDist[$j - 1] + 1;
                $subDist = $prevDist[$j - 1] + $cost;

                if ($subDist <= $delDist && $subDist <= $insDist) {
                    $currDist[$j] = $subDist;
                    $currIns[$j] = $prevIns[$j - 1];
                    $currDel[$j] = $prevDel[$j - 1];
                    $currSub[$j] = $prevSub[$j - 1] + ($cost ? 1 : 0);
                } elseif ($delDist <= $insDist) {
                    $currDist[$j] = $delDist;
                    $currIns[$j] = $prevIns[$j];
                    $currDel[$j] = $prevDel[$j] + 1;
                    $currSub[$j] = $prevSub[$j];
                } else {
                    $currDist[$j] = $insDist;
                    $currIns[$j] = $currIns[$j - 1] + 1;
                    $currDel[$j] = $currDel[$j - 1];
                    $currSub[$j] = $currSub[$j - 1];
                }
            }

            $prevDist = $currDist;
            $prevIns = $currIns;
            $prevDel = $currDel;
            $prevSub = $currSub;
        }

        return [
            'substitutions' => $prevSub[$n],
            'insertions' => $prevIns[$n],
            'deletions' => $prevDel[$n],
            'errors' => [],
            'distance' => $prevDist[$n],
        ];
    }
}
