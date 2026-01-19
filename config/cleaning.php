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
            'processors' => ['special_chars', 'seif_marker', 'title_style', 'whitespace'],
        ],
        'full_clean' => [
            'name' => 'Full Clean (5712+)',
            'description' => 'Removes titles AND inline brackets',
            'processors' => ['special_chars', 'seif_marker', 'title_style', 'brackets_inline', 'whitespace'],
        ],
        'with_editorial' => [
            'name' => 'With Editorial Removal',
            'description' => 'Standard cleaning + editorial Hebrew citations',
            'processors' => ['special_chars', 'seif_marker', 'title_style', 'brackets_inline', 'editorial_hebrew', 'whitespace'],
        ],
        'heavy' => [
            'name' => 'Heavy Cleaning',
            'description' => 'All processors including parentheses and force remove',
            'processors' => ['special_chars', 'seif_marker', 'title_style', 'brackets_inline', 'parentheses', 'editorial_hebrew', 'force_remove', 'whitespace'],
        ],
        'minimal' => [
            'name' => 'Minimal',
            'description' => 'Only whitespace and special character cleanup',
            'processors' => ['special_chars', 'whitespace'],
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
        'force_remove' => \App\Services\Cleaning\Processors\ForceRemoveProcessor::class,
        'editorial_hebrew' => \App\Services\Cleaning\Processors\EditorialHebrewProcessor::class,
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
    | Exception Patterns
    |--------------------------------------------------------------------------
    |
    | Regex patterns for content that should NEVER be removed.
    | If text matches any pattern here, it will be preserved.
    |
    */
    'exception_patterns' => [
        'לחיים',           // "L'chaim" toasts - always keep
        // Add more patterns as needed:
        // '^ב״ה$',        // Keep standalone "B'H"
        // 'מרן.*הרב',      // Keep references to rabbis
    ],

    /*
    |--------------------------------------------------------------------------
    | Force Remove Patterns
    |--------------------------------------------------------------------------
    |
    | Regex patterns for content that should ALWAYS be removed.
    | Matches are removed regardless of other processing rules.
    |
    */
    'force_remove_patterns' => [
        'בס"ד',              // "B'S'D" header
        'כ"ק אד"ש צוה',      // Specific editorial phrase
        'אח"כ צוה לנגן',     // Specific editorial phrase
        'מאמר זה רשמתי',     // Specific editorial phrase
        '-----------',       // Separator lines
        // Add more patterns as needed
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
