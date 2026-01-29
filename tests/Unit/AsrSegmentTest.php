<?php

use App\Services\Asr\AsrSegment;

test('can create segment with all properties', function () {
    $segment = new AsrSegment(
        text: 'Hello world',
        start: 1.5,
        end: 3.5,
        confidence: 0.85,
        words: [
            ['word' => 'Hello', 'start' => 1.5, 'end' => 2.5],
            ['word' => 'world', 'start' => 2.5, 'end' => 3.5],
        ]
    );

    expect($segment->text)->toBe('Hello world');
    expect($segment->start)->toBe(1.5);
    expect($segment->end)->toBe(3.5);
    expect($segment->confidence)->toBe(0.85);
    expect($segment->words)->toHaveCount(2);
});

test('can create segment without optional properties', function () {
    $segment = new AsrSegment(
        text: 'Hello world',
        start: 1.5,
        end: 3.5
    );

    expect($segment->text)->toBe('Hello world');
    expect($segment->confidence)->toBeNull();
    expect($segment->words)->toBeNull();
});

test('can calculate duration', function () {
    $segment = new AsrSegment(
        text: 'Hello',
        start: 1.5,
        end: 3.5
    );

    expect($segment->getDuration())->toBe(2.0);
});

test('can get word count', function () {
    $segment = new AsrSegment(
        text: 'Hello beautiful world',
        start: 0,
        end: 1
    );

    expect($segment->getWordCount())->toBe(3);
});

test('hasWords returns correct value', function () {
    $segmentWithWords = new AsrSegment(
        text: 'Hello',
        start: 0,
        end: 1,
        words: [['word' => 'Hello', 'start' => 0, 'end' => 1]]
    );

    $segmentWithoutWords = new AsrSegment(
        text: 'Hello',
        start: 0,
        end: 1
    );

    expect($segmentWithWords->hasWords())->toBeTrue();
    expect($segmentWithoutWords->hasWords())->toBeFalse();
});

test('can create from array', function () {
    $segment = AsrSegment::fromArray([
        'text' => 'Hello world',
        'start' => 1.5,
        'end' => 3.5,
        'confidence' => 0.9,
        'words' => [
            ['word' => 'Hello', 'start' => 1.5, 'end' => 2.5],
        ],
    ]);

    expect($segment->text)->toBe('Hello world');
    expect($segment->start)->toBe(1.5);
    expect($segment->end)->toBe(3.5);
    expect($segment->confidence)->toBe(0.9);
});

test('can convert to array', function () {
    $segment = new AsrSegment(
        text: 'Hello world',
        start: 1.5,
        end: 3.5,
        confidence: 0.85,
        words: [['word' => 'Hello', 'start' => 1.5, 'end' => 2.5]]
    );

    $array = $segment->toArray();

    expect($array)->toHaveKeys(['text', 'start', 'end', 'confidence', 'words']);
    expect($array['text'])->toBe('Hello world');
    expect($array['confidence'])->toBe(0.85);
});
