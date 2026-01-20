<?php

namespace App\Services\Llm\Drivers;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class GroqDriver implements LlmDriverInterface
{
    public function __construct(
        protected ?string $apiKey,
        protected string $model = 'llama-3.3-70b-versatile',
        protected ?string $baseUrl = 'https://api.groq.com/openai/v1',
    ) {}

    public function complete(string $prompt, array $options = []): string
    {
        if (! $this->apiKey) {
            throw new RuntimeException('Groq API key not configured');
        }

        // Groq uses OpenAI-compatible API
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
            'Content-Type' => 'application/json',
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
                'Groq API error: '.$response->body()
            );
        }

        return $response->json('choices.0.message.content', '');
    }

    public function getModel(): string
    {
        return $this->model;
    }
}
