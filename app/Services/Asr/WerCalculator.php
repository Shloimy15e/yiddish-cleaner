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
    /**
     * Calculate WER and CER metrics between reference and hypothesis texts.
     */
    public function calculate(string $reference, string $hypothesis): WerResult
    {
        // Normalize texts
        $reference = $this->normalize($reference);
        $hypothesis = $this->normalize($hypothesis);

        // Calculate word-level metrics (WER)
        $refWords = $this->tokenizeWords($reference);
        $hypWords = $this->tokenizeWords($hypothesis);
        $wordMetrics = $this->levenshteinDetails($refWords, $hypWords);

        // Calculate character-level metrics (CER)
        $refChars = $this->tokenizeChars($reference);
        $hypChars = $this->tokenizeChars($hypothesis);
        $charMetrics = $this->levenshteinDetails($refChars, $hypChars);

        // Calculate error rates
        $wer = count($refWords) > 0
            ? ($wordMetrics['substitutions'] + $wordMetrics['insertions'] + $wordMetrics['deletions']) / count($refWords) * 100
            : 0.0;

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
        );
    }

    /**
     * Normalize text for comparison.
     */
    protected function normalize(string $text): string
    {
        // Convert to lowercase
        $text = mb_strtolower($text, 'UTF-8');

        // Remove punctuation but keep Hebrew/Yiddish characters
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

        return mb_str_split($text, 1, 'UTF-8');
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
}
