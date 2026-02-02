<?php

use App\Services\Asr\WerCalculator;

describe('WerCalculator', function () {
    it('correctly calculates WER ignoring Nikkud, punctuations, and excess whitespace', function () {
        $calculator = new WerCalculator();

        // Test normalization and perfect match
        $reference = "שָׁלוֹם שלום העולם"; // With Nikkud
        $hypothesis = "שלום שלום   העולם!! \n"; // Without Nikkud + with special characters
        $result = $calculator->calculate($reference, $hypothesis);

        // Expect no errors
        expect($result->wer)->toBe(0.0);
        expect($result->cer)->toBe(0.0);
    });

    it('calculates WER for partial matches', function () {
        $calculator = new WerCalculator();

        $reference = "שלום עולם";
        $hypothesis = "שלום";
        $result = $calculator->calculate($reference, $hypothesis);

        // WER should account for missing word
        expect($result->wer)->toBeGreaterThan(0.0);
        expect($result->cer)->toBeGreaterThan(0.0);
    });

    it('calculates WER for completely different texts', function () {
        $calculator = new WerCalculator();

        $reference = "שלום עולם";
        $hypothesis = "בורא המלך";
        $result = $calculator->calculate($reference, $hypothesis);

        // Maximum error rate expected
        expect($result->wer)->toBe(100.0);
        expect($result->cer)->toBeGreaterThan(0.0);
    });

    it('handles empty strings gracefully', function () {
        $calculator = new WerCalculator();

        $reference = "";
        $hypothesis = "";
        $result = $calculator->calculate($reference, $hypothesis);

        // Expect zero error rate with no data
        expect($result->wer)->toBe(0.0);
        expect($result->cer)->toBe(0.0);
    });

    it('handles empty hypothesis correctly', function () {
        $calculator = new WerCalculator();

        $reference = "שלום עולם";
        $hypothesis = "";
        $result = $calculator->calculate($reference, $hypothesis);

        // WER should be maximum with empty hypothesis
        expect($result->wer)->toBe(100.0);
        expect($result->cer)->toBe(100.0);
    });

    it('calculates WER for a specific word range', function () {
        $calculator = new WerCalculator();

        // Reference: "one two three four five" (5 words)
        // Hypothesis: "one xxx three yyy five" (5 words - 2 different words)
        // Full WER would be 40% (2 substitutions out of 5)
        $reference = "one two three four five";
        $hypothesis = "one xxx three yyy five";

        // Calculate for full text
        $fullResult = $calculator->calculate($reference, $hypothesis);
        expect($fullResult->wer)->toBe(40.0); // 2 subs / 5 words
        expect($fullResult->refStart)->toBe(0);
        expect($fullResult->refEnd)->toBe(4);

        // Calculate for words 0-2 only ("one two three" vs "one xxx three")
        $rangeResult = $calculator->calculate($reference, $hypothesis, 0, 2, 0, 2);
        expect($rangeResult->wer)->toBeGreaterThan(30.0); // 1 sub / 3 words ≈ 33.33%
        expect($rangeResult->wer)->toBeLessThan(35.0);
        expect($rangeResult->refStart)->toBe(0);
        expect($rangeResult->refEnd)->toBe(2);
        expect($rangeResult->referenceWords)->toBe(3);

        // Calculate for words 2-4 only ("three four five" vs "three yyy five")
        $rangeResult2 = $calculator->calculate($reference, $hypothesis, 2, 4, 2, 4);
        expect($rangeResult2->wer)->toBeGreaterThan(30.0); // 1 sub / 3 words ≈ 33.33%
        expect($rangeResult2->wer)->toBeLessThan(35.0);
        expect($rangeResult2->refStart)->toBe(2);
        expect($rangeResult2->refEnd)->toBe(4);
    });

    it('stores range information in result', function () {
        $calculator = new WerCalculator();

        $reference = "word1 word2 word3 word4";
        $hypothesis = "word1 word2 word3 word4";

        $result = $calculator->calculate($reference, $hypothesis, 1, 2, 1, 3);

        expect($result->refStart)->toBe(1);
        expect($result->refEnd)->toBe(2);
        expect($result->hypStart)->toBe(1);
        expect($result->hypEnd)->toBe(3);

        // Check toArray includes range
        $array = $result->toArray();
        expect($array)->toHaveKey('wer_ref_start');
        expect($array)->toHaveKey('wer_ref_end');
        expect($array)->toHaveKey('wer_hyp_start');
        expect($array)->toHaveKey('wer_hyp_end');
    });

    it('ignores specified words when counting insertions', function () {
        $calculator = new WerCalculator();

        // Reference: "hello world"
        // Hypothesis: "hello um world" - has extra "um"
        $reference = "hello world";
        $hypothesis = "hello um world";

        // Without ignored words, "um" counts as an insertion
        $resultWithInsertion = $calculator->calculate($reference, $hypothesis);
        expect($resultWithInsertion->insertions)->toBe(1);
        expect($resultWithInsertion->wer)->toBe(50.0); // 1 insertion / 2 ref words = 50%

        // With "um" in ignored list, it's filtered from hypothesis
        $resultIgnored = $calculator->calculate($reference, $hypothesis, ignoredInsertionWords: ['um']);
        expect($resultIgnored->insertions)->toBe(0);
        expect($resultIgnored->wer)->toBe(0.0); // Perfect match after filtering

        // Multiple ignored words
        $hypothesis2 = "hello um uh world ah";
        $resultMultiple = $calculator->calculate($reference, $hypothesis2, ignoredInsertionWords: ['um', 'uh', 'ah']);
        expect($resultMultiple->insertions)->toBe(0);
        expect($resultMultiple->wer)->toBe(0.0);
    });

    it('handles case-insensitive ignored words', function () {
        $calculator = new WerCalculator();

        $reference = "hello world";
        $hypothesis = "hello UM World"; // Uppercase UM

        // Ignored words should match case-insensitively
        $result = $calculator->calculate($reference, $hypothesis, ignoredInsertionWords: ['um']);
        expect($result->insertions)->toBe(0);
        expect($result->wer)->toBe(0.0);
    });
});