<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cleaning Presets
    |--------------------------------------------------------------------------
    |
    | Define preset configurations for cleaning documents. Each preset
    | specifies which processors to run and in what order.
    |
    */
    'presets' => [
        'titles_only' => [
            'name' => 'Titles Only (5710-5711)',
            'description' => 'Removes titles/headings, keeps brackets',
            'processors' => ['whitespace', 'special_chars', 'title_style', 'seif_marker'],
        ],
        'full_clean' => [
            'name' => 'Full Clean (5712+)',
            'description' => 'Removes titles AND inline brackets',
            'processors' => ['whitespace', 'special_chars', 'title_style', 'seif_marker', 'brackets_inline', 'parentheses'],
        ],
        'minimal' => [
            'name' => 'Minimal',
            'description' => 'Only whitespace normalization',
            'processors' => ['whitespace', 'special_chars'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Processors
    |--------------------------------------------------------------------------
    |
    | Map processor names to their implementing classes. Each processor
    | must implement ProcessorInterface.
    |
    */
    'processors' => [
        'whitespace' => \App\Services\Cleaning\Processors\WhitespaceProcessor::class,
        'special_chars' => \App\Services\Cleaning\Processors\SpecialCharsProcessor::class,
        'title_style' => \App\Services\Cleaning\Processors\TitleStyleProcessor::class,
        'seif_marker' => \App\Services\Cleaning\Processors\SeifMarkerProcessor::class,
        'brackets_inline' => \App\Services\Cleaning\Processors\BracketsProcessor::class,
        'parentheses' => \App\Services\Cleaning\Processors\ParenthesesProcessor::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | LLM Providers
    |--------------------------------------------------------------------------
    |
    | Configuration for LLM providers used for AI-assisted cleaning.
    |
    */
    'llm_providers' => [
        'openrouter' => [
            'driver' => \App\Services\Llm\Drivers\OpenRouterDriver::class,
            'default_model' => 'anthropic/claude-sonnet-4',
            'base_url' => 'https://openrouter.ai/api/v1',
        ],
        'openai' => [
            'driver' => \App\Services\Llm\Drivers\OpenAiDriver::class,
            'default_model' => 'gpt-4o',
            'base_url' => 'https://api.openai.com/v1',
        ],
        'anthropic' => [
            'driver' => \App\Services\Llm\Drivers\AnthropicDriver::class,
            'default_model' => 'claude-sonnet-4-20250514',
            'base_url' => 'https://api.anthropic.com/v1',
        ],
        'google' => [
            'driver' => \App\Services\Llm\Drivers\GoogleDriver::class,
            'default_model' => 'gemini-1.5-pro',
        ],
        'groq' => [
            'driver' => \App\Services\Llm\Drivers\GroqDriver::class,
            'default_model' => 'llama-3.3-70b-versatile',
            'base_url' => 'https://api.groq.com/openai/v1',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Clean Rate Thresholds
    |--------------------------------------------------------------------------
    |
    | Thresholds for categorizing clean rate scores.
    |
    */
    'clean_rate_thresholds' => [
        'excellent' => 90,
        'good' => 75,
        'moderate' => 50,
        'low' => 25,
        // Below 25 = 'poor'
    ],

    /*
    |--------------------------------------------------------------------------
    | Default LLM Prompt
    |--------------------------------------------------------------------------
    |
    | The default prompt template for LLM-assisted cleaning.
    |
    */
    'default_llm_prompt' => <<<'PROMPT'
You are a transcript cleaner for Yiddish religious texts. Your task is to clean the following transcript by removing:

1. Editorial notes and annotations
2. Timestamps and time markers
3. Speaker labels and annotations
4. Bracketed editorial content [like this]
5. Parenthetical notes that are not part of the original speech
6. Section titles and headers

Keep ONLY the actual spoken content. Preserve the original Yiddish text exactly as spoken.

Document to clean:
{document_text}

Return ONLY the cleaned text, nothing else.
PROMPT,

    /*
    |--------------------------------------------------------------------------
    | LibreOffice Path
    |--------------------------------------------------------------------------
    |
    | Path to the LibreOffice binary for document conversion.
    |
    */
    'libreoffice_path' => env('LIBREOFFICE_PATH', 'soffice'),

    /*
    |--------------------------------------------------------------------------
    | Temp Directory
    |--------------------------------------------------------------------------
    |
    | Directory for temporary file storage during processing.
    |
    */
    'temp_dir' => storage_path('app/temp'),
];
