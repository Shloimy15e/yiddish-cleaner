<?php

use App\Models\Transcription;
use App\Models\TranscriptionWord;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

it('returns null custom_wer when no standard WER data exists', function () {
    $transcription = Transcription::factory()->asr()->create();

    expect($transcription->custom_wer)->toBeNull();
    expect($transcription->reviewed_word_count)->toBe(0);
    expect($transcription->custom_wer_error_count)->toBe(0);
});

it('uses levenshtein insertions and deletions as base', function () {
    $transcription = Transcription::factory()->asr()->create([
        'insertions' => 3,
        'deletions' => 2,
        'substitutions' => 5,
        'reference_words' => 100,
    ]);

    // No word review done — critical replacements = 0
    expect($transcription->custom_wer_insertion_count)->toBe(3);
    expect($transcription->custom_wer_deletion_count)->toBe(2);
    expect($transcription->custom_wer_replacement_count)->toBe(5);
    expect($transcription->custom_wer_critical_replacement_count)->toBe(0);
    expect($transcription->custom_wer_error_count)->toBe(5);
    expect($transcription->reviewed_word_count)->toBe(100);
    // (3 + 2 + 0) / 100 × 100 = 5.0
    expect($transcription->custom_wer)->toBe(5.0);
});

it('counts critical error flag without requiring corrected_word', function () {
    $transcription = Transcription::factory()->asr()->create([
        'insertions' => 0,
        'deletions' => 0,
        'substitutions' => 5,
        'reference_words' => 100,
    ]);

    // Word marked as critical error but NOT corrected (no corrected_word)
    TranscriptionWord::factory()->count(3)->criticalError()->create([
        'transcription_id' => $transcription->id,
    ]);

    expect($transcription->custom_wer_critical_replacement_count)->toBe(3);
    expect($transcription->custom_wer_error_count)->toBe(3);
    // (0 + 0 + 3) / 100 × 100 = 3.0
    expect($transcription->custom_wer)->toBe(3.0);
});

it('adds critical replacements from word review to levenshtein base', function () {
    $transcription = Transcription::factory()->asr()->create([
        'insertions' => 3,
        'deletions' => 2,
        'substitutions' => 10,
        'reference_words' => 100,
    ]);

    TranscriptionWord::factory()->count(5)->create([
        'transcription_id' => $transcription->id,
    ]);
    // Mix of corrected+critical and just critical (both should count)
    TranscriptionWord::factory()->count(2)->corrected()->criticalError()->create([
        'transcription_id' => $transcription->id,
    ]);
    TranscriptionWord::factory()->count(2)->criticalError()->create([
        'transcription_id' => $transcription->id,
    ]);

    expect($transcription->custom_wer_insertion_count)->toBe(3);
    expect($transcription->custom_wer_deletion_count)->toBe(2);
    expect($transcription->custom_wer_critical_replacement_count)->toBe(4);
    expect($transcription->custom_wer_replacement_count)->toBe(10);
    expect($transcription->custom_wer_error_count)->toBe(9);
    // (3 + 2 + 4) / 100 × 100 = 9.0
    expect($transcription->custom_wer)->toBe(9.0);
});

it('does not count non-critical corrected words in custom_wer', function () {
    $transcription = Transcription::factory()->asr()->create([
        'insertions' => 1,
        'deletions' => 1,
        'substitutions' => 8,
        'reference_words' => 50,
    ]);

    // 8 corrected words but NOT critical — should not count
    TranscriptionWord::factory()->count(8)->corrected()->create([
        'transcription_id' => $transcription->id,
    ]);
    // 2 critical (one with correction, one without) — both should count
    TranscriptionWord::factory()->corrected()->criticalError()->create([
        'transcription_id' => $transcription->id,
    ]);
    TranscriptionWord::factory()->criticalError()->create([
        'transcription_id' => $transcription->id,
    ]);

    expect($transcription->custom_wer_critical_replacement_count)->toBe(2);
    expect($transcription->custom_wer_error_count)->toBe(4);
    // (1 + 1 + 2) / 50 × 100 = 8.0
    expect($transcription->custom_wer)->toBe(8.0);
});

it('respects custom range for critical replacement counting', function () {
    $transcription = Transcription::factory()->asr()->create([
        'insertions' => 2,
        'deletions' => 1,
        'substitutions' => 5,
        'reference_words' => 20,
        'wer_hyp_start' => 2,
        'wer_hyp_end' => 5,
    ]);

    // Critical at index 3 (in range)
    TranscriptionWord::factory()->criticalError()->create([
        'transcription_id' => $transcription->id,
        'word_index' => 3,
    ]);
    // Critical at index 7 (out of range)
    TranscriptionWord::factory()->criticalError()->create([
        'transcription_id' => $transcription->id,
        'word_index' => 7,
    ]);

    // Only index 3 is in range 2-5
    expect($transcription->custom_wer_critical_replacement_count)->toBe(1);
    expect($transcription->custom_wer_error_count)->toBe(4);
    // (2 + 1 + 1) / 20 × 100 = 20.0
    expect($transcription->custom_wer)->toBe(20.0);
});

it('does not double-count deleted words marked as critical', function () {
    $transcription = Transcription::factory()->asr()->create([
        'insertions' => 0,
        'deletions' => 3,
        'substitutions' => 2,
        'reference_words' => 50,
    ]);

    // Deleted AND critical — should NOT count as critical replacement
    TranscriptionWord::factory()->count(2)->deleted()->criticalError()->create([
        'transcription_id' => $transcription->id,
    ]);

    expect($transcription->custom_wer_critical_replacement_count)->toBe(0);
    expect($transcription->custom_wer_error_count)->toBe(3);
    // (0 + 3 + 0) / 50 × 100 = 6.0
    expect($transcription->custom_wer)->toBe(6.0);
});

it('returns lower custom_wer than standard wer when fewer critical substitutions', function () {
    // Standard WER: (10 + 5 + 15) / 200 × 100 = 15.0%
    $transcription = Transcription::factory()->asr()->create([
        'wer' => 15.0,
        'insertions' => 10,
        'deletions' => 5,
        'substitutions' => 15,
        'reference_words' => 200,
    ]);

    // Only 3 out of 15 substitutions are critical
    TranscriptionWord::factory()->count(3)->criticalError()->create([
        'transcription_id' => $transcription->id,
    ]);

    // Custom WER: (10 + 5 + 3) / 200 × 100 = 9.0%
    expect($transcription->custom_wer)->toBe(9.0);
    expect($transcription->custom_wer)->toBeLessThan($transcription->wer);
});

it('equals standard wer when all substitutions are critical', function () {
    $transcription = Transcription::factory()->asr()->create([
        'wer' => 10.0,
        'insertions' => 5,
        'deletions' => 3,
        'substitutions' => 12,
        'reference_words' => 200,
    ]);

    // Mark all 12 substitutions as critical in review
    TranscriptionWord::factory()->count(12)->criticalError()->create([
        'transcription_id' => $transcription->id,
    ]);

    // Custom WER: (5 + 3 + 12) / 200 × 100 = 10.0%
    expect($transcription->custom_wer)->toBe(10.0);
    expect($transcription->custom_wer)->toBe($transcription->wer);
});
