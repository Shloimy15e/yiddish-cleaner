<?php

use App\Services\Alignment\Drivers\LocalWhisperXDriver;
use App\Services\Alignment\Drivers\WhisperXDriver;

return [
    /*
    |--------------------------------------------------------------------------
    | Alignment Providers
    |--------------------------------------------------------------------------
    |
    | Configuration for forced alignment providers. These services take
    | audio and text as input and return word-level timing information.
    |
    */

    'providers' => [
        'local_whisperx' => [
            'driver' => LocalWhisperXDriver::class,
            'name' => 'Local WhisperX',
            'python_path' => env('PYTHON_PATH', 'python'),
            'script_path' => base_path('scripts/align.py'),
            'device' => env('WHISPERX_DEVICE', 'auto'), // auto, cpu, or cuda
            'compute_type' => env('WHISPERX_COMPUTE_TYPE', 'float16'),
            'default_model' => 'large-v2',
            'models' => [
                'large-v2',
                'large-v3',
                'medium',
                'small',
                'base',
            ],
            'async' => false,
            'requires_credential' => false, // No API key needed
            'description' => 'Local Python WhisperX forced alignment (requires whisperx installed)',
        ],

        'whisperx' => [
            'driver' => WhisperXDriver::class,
            'name' => 'WhisperX API',
            'base_url' => env('WHISPERX_BASE_URL', 'http://localhost:8000'),
            'default_model' => 'wav2vec2-large',
            'models' => [
                'wav2vec2-large',
                'wav2vec2-base',
            ],
            'async' => false,
            'requires_credential' => true,
            'description' => 'WhisperX API server (self-hosted or cloud)',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Provider
    |--------------------------------------------------------------------------
    |
    | The default alignment provider to use when none is specified.
    |
    */

    'default' => env('ALIGNMENT_DEFAULT_PROVIDER', 'local_whisperx'),

    /*
    |--------------------------------------------------------------------------
    | Alignment Settings
    |--------------------------------------------------------------------------
    |
    | General settings for alignment processing.
    |
    */

    'timeout' => env('ALIGNMENT_TIMEOUT', 300), // Max seconds to wait for alignment

    'poll_interval' => env('ALIGNMENT_POLL_INTERVAL', 3), // Initial poll interval in seconds

    'max_poll_interval' => env('ALIGNMENT_MAX_POLL_INTERVAL', 15), // Max poll interval

    /*
    |--------------------------------------------------------------------------
    | Word Alignment Quality
    |--------------------------------------------------------------------------
    |
    | Settings related to alignment quality and confidence thresholds.
    |
    */

    'quality' => [
        // Minimum acceptable confidence for aligned words (0-1)
        'min_confidence' => env('ALIGNMENT_MIN_CONFIDENCE', 0.5),

        // Threshold for flagging low-confidence alignments
        'low_confidence_threshold' => env('ALIGNMENT_LOW_CONFIDENCE_THRESHOLD', 0.7),
    ],
];
