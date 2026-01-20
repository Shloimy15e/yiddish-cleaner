<?php

use App\Services\Asr\Drivers\WhisperDriver;
use App\Services\Asr\Drivers\YiddishLabsDriver;

return [
    /*
    |--------------------------------------------------------------------------
    | ASR Providers
    |--------------------------------------------------------------------------
    |
    | Configuration for Automatic Speech Recognition (ASR) providers.
    | Each provider must have a driver class implementing AsrDriverInterface.
    |
    */

    'providers' => [
        'yiddishlabs' => [
            'driver' => YiddishLabsDriver::class,
            'name' => 'YiddishLabs',
            'default_model' => 'yiddish-libre',
            'models' => [
                'yiddish-libre',
            ],
            'async' => true,
            'description' => 'Specialized Yiddish ASR service',
        ],

        'whisper' => [
            'driver' => WhisperDriver::class,
            'name' => 'OpenAI Whisper',
            'default_model' => 'whisper-1',
            'models' => [
                'whisper-1',
            ],
            'async' => false,
            'description' => 'OpenAI Whisper with Yiddish language hint',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Provider
    |--------------------------------------------------------------------------
    |
    | The default ASR provider to use when none is specified.
    |
    */

    'default' => env('ASR_DEFAULT_PROVIDER', 'yiddishlabs'),

    /*
    |--------------------------------------------------------------------------
    | Transcription Settings
    |--------------------------------------------------------------------------
    |
    | General settings for transcription processing.
    |
    */

    'timeout' => env('ASR_TIMEOUT', 600), // Max seconds to wait for transcription

    'poll_interval' => env('ASR_POLL_INTERVAL', 5), // Initial poll interval in seconds

    'max_poll_interval' => env('ASR_MAX_POLL_INTERVAL', 30), // Max poll interval
];
