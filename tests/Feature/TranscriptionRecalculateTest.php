<?php

use App\Models\AudioSample;
use App\Models\Transcription;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

it('recalculates WER with null range values', function () {
    $user = User::factory()->create();
    $audioSample = AudioSample::factory()->create();

    $base = Transcription::factory()->base()->create([
        'audio_sample_id' => $audioSample->id,
        'text_clean' => 'אַ גוטן טאָג איך בין דאָ',
    ]);

    $asr = Transcription::factory()->asr()->create([
        'audio_sample_id' => $audioSample->id,
        'hypothesis_text' => 'אַ גוטן טאָג איך בין דאָ',
    ]);

    $this->actingAs($user)
        ->post(route('transcriptions.recalculate', [
            'audioSample' => $audioSample->id,
            'transcription' => $asr->id,
        ]), [
            'ref_start' => null,
            'ref_end' => null,
            'hyp_start' => null,
            'hyp_end' => null,
        ])
        ->assertRedirect();

    $asr->refresh();
    expect($asr->wer)->not->toBeNull();
});

it('recalculates WER with specific range values', function () {
    $user = User::factory()->create();
    $audioSample = AudioSample::factory()->create();

    Transcription::factory()->base()->create([
        'audio_sample_id' => $audioSample->id,
        'text_clean' => 'אַ גוטן טאָג איך בין דאָ היינט',
    ]);

    $asr = Transcription::factory()->asr()->create([
        'audio_sample_id' => $audioSample->id,
        'hypothesis_text' => 'אַ גוטן טאָג איך בין דאָ היינט',
    ]);

    $this->actingAs($user)
        ->post(route('transcriptions.recalculate', [
            'audioSample' => $audioSample->id,
            'transcription' => $asr->id,
        ]), [
            'ref_start' => 1,
            'ref_end' => 4,
            'hyp_start' => 1,
            'hyp_end' => 4,
        ])
        ->assertRedirect();

    $asr->refresh();
    expect($asr->wer_ref_start)->toBe(1);
    expect($asr->wer_ref_end)->toBe(4);
    expect($asr->wer_hyp_start)->toBe(1);
    expect($asr->wer_hyp_end)->toBe(4);
});

it('recalculates WER when empty strings are sent for range values', function () {
    $user = User::factory()->create();
    $audioSample = AudioSample::factory()->create();

    Transcription::factory()->base()->create([
        'audio_sample_id' => $audioSample->id,
        'text_clean' => 'אַ גוטן טאָג איך בין דאָ',
    ]);

    $asr = Transcription::factory()->asr()->create([
        'audio_sample_id' => $audioSample->id,
        'hypothesis_text' => 'אַ גוטן טאָג איך בין דאָ',
    ]);

    // The Form component sends empty strings for null hidden input values
    // Laravel's ConvertEmptyStringsToNull middleware converts these to null
    $this->actingAs($user)
        ->post(route('transcriptions.recalculate', [
            'audioSample' => $audioSample->id,
            'transcription' => $asr->id,
        ]), [
            'ref_start' => '',
            'ref_end' => '',
            'hyp_start' => '',
            'hyp_end' => '',
        ])
        ->assertRedirect();

    $asr->refresh();
    expect($asr->wer)->not->toBeNull();
    // When null range is sent, calculator defaults to full range (start=0)
    expect($asr->wer_ref_start)->toBe(0);
});

it('recalculates ASR WER synchronously when base transcription text_clean is updated', function () {
    $user = User::factory()->create();
    $audioSample = AudioSample::factory()->create();

    $base = Transcription::factory()->base()->create([
        'audio_sample_id' => $audioSample->id,
        'text_clean' => 'אַ גוטן טאָג',
    ]);

    $asr = Transcription::factory()->asr()->create([
        'audio_sample_id' => $audioSample->id,
        'hypothesis_text' => 'אַ גוטן טאָג',
        'wer' => 10.0,
    ]);

    // Update base transcription text_clean to match hypothesis exactly
    $this->actingAs($user)
        ->patch(route('transcriptions.update', $base), [
            'text_clean' => 'אַ גוטן טאָג',
        ])
        ->assertRedirect();

    // WER should have been recalculated synchronously (0% since texts match)
    $asr->refresh();
    expect($asr->wer)->toBe(0.0);
});

it('updates ASR WER when base transcription text diverges from hypothesis', function () {
    $user = User::factory()->create();
    $audioSample = AudioSample::factory()->create();

    $base = Transcription::factory()->base()->create([
        'audio_sample_id' => $audioSample->id,
        'text_clean' => 'אַ גוטן טאָג',
        'hash_clean' => hash('sha256', 'אַ גוטן טאָג'),
    ]);

    // First calculate initial WER
    $asr = Transcription::factory()->asr()->create([
        'audio_sample_id' => $audioSample->id,
        'hypothesis_text' => 'אַ גוטן טאָג',
        'wer' => 0.0,
    ]);

    // Change base text to something different from hypothesis
    $this->actingAs($user)
        ->patch(route('transcriptions.update', $base), [
            'text_clean' => 'אַ שלעכטן טאָג איך בין דאָ',
        ])
        ->assertRedirect();

    // WER should now be non-zero since texts differ
    $asr->refresh();
    expect($asr->wer)->toBeGreaterThan(0.0);
});
