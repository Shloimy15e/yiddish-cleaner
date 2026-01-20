<?php

namespace App\Services\Llm\Drivers;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class AnthropicDriver implements LlmDriverInterface
{
    public function __construct(
        protected ?string $apiKey,
        protected string $model = 'claude-sonnet-4-20250514',
        protected ?string $baseUrl = 'https://api.anthropic.com/v1',
    ) {}

    public function complete(string $prompt, array $options = []): string
    {
        if (! $this->apiKey) {
            throw new RuntimeException('Anthropic API key not configured');
        }

        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'Content-Type' => 'application/json',
            'anthropic-version' => '2023-06-01',
        ])->timeout(120)->post("{$this->baseUrl}/messages", [
            'model' => $options['model'] ?? $this->model,
            'max_tokens' => $options['max_tokens'] ?? 4096,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'Anthropic API error: '.$response->body()
            );
        }

        $content = $response->json('content', []);

        return $content[0]['text'] ?? '';
    }

    public function getModel(): string
    {
        return $this->model;
    }
}
