<?php

use App\Models\Transcription;
use App\Models\TranscriptionSegment;
use App\Models\User;
use App\Services\Asr\AsrSegment;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('transcription can store segments', function () {
    $transcription = Transcription::factory()->create();

    $segments = [
        new AsrSegment(
            text: 'First sentence.',
            start: 0.0,
            end: 2.0,
            confidence: 0.9,
            words: [
                ['word' => 'First', 'start' => 0.0, 'end' => 0.5],
                ['word' => 'sentence', 'start' => 0.5, 'end' => 2.0],
            ]
        ),
        new AsrSegment(
            text: 'Second sentence.',
            start: 2.0,
            end: 4.0,
            confidence: 0.85
        ),
    ];

    $transcription->storeSegments($segments);

    expect($transcription->segments()->count())->toBe(2);
    expect($transcription->hasSegmentData())->toBeTrue();
});

test('transcription segment model has correct attributes', function () {
    $transcription = Transcription::factory()->create();

    $segment = TranscriptionSegment::create([
        'transcription_id' => $transcription->id,
        'segment_index' => 0,
        'text' => 'Hello world',
        'start_time' => 1.5,
        'end_time' => 3.5,
        'confidence' => 0.85,
        'words_json' => [['word' => 'Hello', 'start' => 1.5, 'end' => 2.5]],
    ]);

    expect($segment->text)->toBe('Hello world');
    expect((float) $segment->start_time)->toBe(1.5);
    expect((float) $segment->confidence)->toBe(0.85);
    expect($segment->words_json)->toBeArray();
    expect($segment->hasWords())->toBeTrue();
    expect($segment->getDisplayText())->toBe('Hello world');
    expect($segment->getDuration())->toBe(2.0);
});

test('segment correction works correctly', function () {
    $transcription = Transcription::factory()->create();
    $user = User::factory()->create();

    $segment = TranscriptionSegment::create([
        'transcription_id' => $transcription->id,
        'segment_index' => 0,
        'text' => 'Original text',
        'start_time' => 0,
        'end_time' => 2,
    ]);

    expect($segment->isCorrected())->toBeFalse();

    $segment->applyCorrection('Corrected text', $user->id);

    expect($segment->isCorrected())->toBeTrue();
    expect($segment->corrected_text)->toBe('Corrected text');
    expect($segment->corrected_by)->toBe($user->id);
    expect($segment->getDisplayText())->toBe('Corrected text');
});

test('segment clear correction works', function () {
    $transcription = Transcription::factory()->create();
    $user = User::factory()->create();

    $segment = TranscriptionSegment::create([
        'transcription_id' => $transcription->id,
        'segment_index' => 0,
        'text' => 'Original text',
        'corrected_text' => 'Corrected text',
        'corrected_by' => $user->id,
        'corrected_at' => now(),
        'start_time' => 0,
        'end_time' => 2,
    ]);

    $segment->clearCorrection();

    expect($segment->isCorrected())->toBeFalse();
    expect($segment->corrected_text)->toBeNull();
    expect($segment->corrected_by)->toBeNull();
});

test('authenticated user can update segment', function () {
    $user = User::factory()->create();
    $transcription = Transcription::factory()->create();

    $segment = TranscriptionSegment::create([
        'transcription_id' => $transcription->id,
        'segment_index' => 0,
        'text' => 'Original text',
        'start_time' => 0,
        'end_time' => 2,
    ]);

    $response = $this->actingAs($user)
        ->patch(route('transcriptions.segments.update', [$transcription, $segment]), [
            'corrected_text' => 'New corrected text',
        ]);

    $response->assertRedirect();

    $segment->refresh();
    expect($segment->corrected_text)->toBe('New corrected text');
    expect($segment->corrected_by)->toBe($user->id);
});

test('segment scopes work correctly', function () {
    $transcription = Transcription::factory()->create();

    TranscriptionSegment::create([
        'transcription_id' => $transcription->id,
        'segment_index' => 0,
        'text' => 'High confidence',
        'start_time' => 0,
        'end_time' => 2,
        'confidence' => 0.95,
    ]);

    TranscriptionSegment::create([
        'transcription_id' => $transcription->id,
        'segment_index' => 1,
        'text' => 'Low confidence',
        'start_time' => 2,
        'end_time' => 4,
        'confidence' => 0.4,
    ]);

    TranscriptionSegment::create([
        'transcription_id' => $transcription->id,
        'segment_index' => 2,
        'text' => 'Corrected segment',
        'corrected_text' => 'Fixed text',
        'start_time' => 4,
        'end_time' => 6,
        'confidence' => 0.8,
    ]);

    expect($transcription->segments()->belowConfidence(0.5)->count())->toBe(1);
    expect($transcription->segments()->corrected()->count())->toBe(1);
    expect($transcription->segments()->needsReview(0.5)->count())->toBe(1);
});
