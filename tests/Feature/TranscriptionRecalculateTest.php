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
