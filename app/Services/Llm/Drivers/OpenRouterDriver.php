<?php

namespace App\Services\Llm\Drivers;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * OpenRouter LLM Driver
 *
 * OpenRouter provides access to many models through a unified OpenAI-compatible API.
 *
 * @see https://openrouter.ai/docs
 */
class OpenRouterDriver implements LlmDriverInterface
{
    public function __construct(
        protected ?string $apiKey,
        protected string $model = 'anthropic/claude-sonnet-4',
        protected ?string $baseUrl = 'https://openrouter.ai/api/v1',
    ) {}

    public function complete(string $prompt, array $options = []): string
    {
        if (! $this->apiKey) {
            throw new RuntimeException('OpenRouter API key not configured');
        }

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
            'Content-Type' => 'application/json',
            'HTTP-Referer' => config('app.url', 'http://localhost'),
            'X-Title' => config('app.name', 'Yiddish Cleaner'),
        ])->timeout(120)->post("{$this->baseUrl}/chat/completions", [
            'model' => $options['model'] ?? $this->model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => $options['temperature'] ?? 0.3,
            'max_tokens' => $options['max_tokens'] ?? 4096,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'OpenRouter API error: '.$response->body()
            );
        }

        return $response->json('choices.0.message.content', '');
    }

    public function getModel(): string
    {
        return $this->model;
    }
}
