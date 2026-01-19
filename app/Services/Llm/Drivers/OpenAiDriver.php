<?php

namespace App\Services\Llm\Drivers;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiDriver implements LlmDriverInterface
{
    public function __construct(
        protected ?string $apiKey,
        protected string $model = 'gpt-4o',
        protected ?string $baseUrl = 'https://api.openai.com/v1',
    ) {}

    public function complete(string $prompt, array $options = []): string
    {
        if (!$this->apiKey) {
            throw new RuntimeException('OpenAI API key not configured');
        }

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

        if (!$response->successful()) {
            throw new RuntimeException(
                "OpenAI API error: " . $response->body()
            );
        }

        return $response->json('choices.0.message.content', '');
    }

    public function getModel(): string
    {
        return $this->model;
    }
}
