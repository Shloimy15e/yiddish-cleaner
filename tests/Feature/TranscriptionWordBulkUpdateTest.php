<?php

use App\Models\Transcription;
use App\Models\TranscriptionWord;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('authenticated user can bulk delete words', function () {
    $user = User::factory()->create();
    $transcription = Transcription::factory()->create();

    $word1 = TranscriptionWord::create([
        'transcription_id' => $transcription->id,
        'word_index' => 0,
        'word' => 'first',
        'start_time' => 0,
        'end_time' => 1,
    ]);

    $word2 = TranscriptionWord::create([
        'transcription_id' => $transcription->id,
        'word_index' => 1,
        'word' => 'second',
        'start_time' => 1,
        'end_time' => 2,
    ]);

    $response = $this->actingAs($user)
        ->post(route('transcriptions.words.bulk', $transcription), [
            'word_ids' => [$word1->id, $word2->id],
            'action' => 'delete',
        ]);

    $response->assertRedirect();

    $word1->refresh();
    $word2->refresh();
    expect($word1->is_deleted)->toBeTrue();
    expect($word2->is_deleted)->toBeTrue();
});

test('authenticated user can bulk mark words as critical error', function () {
    $user = User::factory()->create();
    $transcription = Transcription::factory()->create();

    $word1 = TranscriptionWord::create([
        'transcription_id' => $transcription->id,
        'word_index' => 0,
        'word' => 'first',
        'start_time' => 0,
        'end_time' => 1,
    ]);

    $word2 = TranscriptionWord::create([
        'transcription_id' => $transcription->id,
        'word_index' => 1,
        'word' => 'second',
        'start_time' => 1,
        'end_time' => 2,
    ]);

    $response = $this->actingAs($user)
        ->post(route('transcriptions.words.bulk', $transcription), [
            'word_ids' => [$word1->id, $word2->id],
            'action' => 'mark_critical_error',
        ]);

    $response->assertRedirect();

    $word1->refresh();
    $word2->refresh();
    expect($word1->is_critical_error)->toBeTrue();
    expect($word2->is_critical_error)->toBeTrue();
});

test('authenticated user can bulk clear critical error from words', function () {
    $user = User::factory()->create();
    $transcription = Transcription::factory()->create();

    $word1 = TranscriptionWord::create([
        'transcription_id' => $transcription->id,
        'word_index' => 0,
        'word' => 'first',
        'start_time' => 0,
        'end_time' => 1,
        'is_critical_error' => true,
    ]);

    $word2 = TranscriptionWord::create([
        'transcription_id' => $transcription->id,
        'word_index' => 1,
        'word' => 'second',
        'start_time' => 1,
        'end_time' => 2,
        'is_critical_error' => true,
    ]);

    $response = $this->actingAs($user)
        ->post(route('transcriptions.words.bulk', $transcription), [
            'word_ids' => [$word1->id, $word2->id],
            'action' => 'clear_critical_error',
        ]);

    $response->assertRedirect();

    $word1->refresh();
    $word2->refresh();
    expect($word1->is_critical_error)->toBeFalse();
    expect($word2->is_critical_error)->toBeFalse();
});

test('bulk delete permanently removes inserted words', function () {
    $user = User::factory()->create();
    $transcription = Transcription::factory()->create();

    $insertedWord = TranscriptionWord::create([
        'transcription_id' => $transcription->id,
        'word_index' => 0,
        'word' => 'inserted',
        'start_time' => 0,
        'end_time' => 1,
        'is_inserted' => true,
    ]);

    $wordId = $insertedWord->id;

    $response = $this->actingAs($user)
        ->post(route('transcriptions.words.bulk', $transcription), [
            'word_ids' => [$wordId],
            'action' => 'delete',
        ]);

    $response->assertRedirect();

    expect(TranscriptionWord::find($wordId))->toBeNull();
});

test('bulk update rejects words from different transcription', function () {
    $user = User::factory()->create();
    $transcription1 = Transcription::factory()->create();
    $transcription2 = Transcription::factory()->create();

    $word = TranscriptionWord::create([
        'transcription_id' => $transcription2->id,
        'word_index' => 0,
        'word' => 'other',
        'start_time' => 0,
        'end_time' => 1,
    ]);

    // Trying to bulk update word from transcription2 via transcription1's route
    $response = $this->actingAs($user)
        ->post(route('transcriptions.words.bulk', $transcription1), [
            'word_ids' => [$word->id],
            'action' => 'mark_critical_error',
        ]);

    $response->assertRedirect();

    // Word should NOT be updated since it belongs to a different transcription
    $word->refresh();
    expect($word->is_critical_error)->toBeFalse();
});

test('bulk update requires valid action', function () {
    $user = User::factory()->create();
    $transcription = Transcription::factory()->create();

    $word = TranscriptionWord::create([
        'transcription_id' => $transcription->id,
        'word_index' => 0,
        'word' => 'test',
        'start_time' => 0,
        'end_time' => 1,
    ]);

    $response = $this->actingAs($user)
        ->post(route('transcriptions.words.bulk', $transcription), [
            'word_ids' => [$word->id],
            'action' => 'invalid_action',
        ]);

    $response->assertSessionHasErrors('action');
});
