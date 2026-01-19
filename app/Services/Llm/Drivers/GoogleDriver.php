<?php

namespace App\Services\Llm\Drivers;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleDriver implements LlmDriverInterface
{
    public function __construct(
        protected ?string $apiKey,
        protected string $model = 'gemini-1.5-pro',
        protected ?string $baseUrl = null,
    ) {}

    public function complete(string $prompt, array $options = []): string
    {
        if (!$this->apiKey) {
            throw new RuntimeException('Google AI API key not configured');
        }

        $model = $options['model'] ?? $this->model;
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->timeout(120)->post("{$url}?key={$this->apiKey}", [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => $options['temperature'] ?? 0.3,
                'maxOutputTokens' => $options['max_tokens'] ?? 4096,
            ],
        ]);

        if (!$response->successful()) {
            throw new RuntimeException(
                "Google AI API error: " . $response->body()
            );
        }

        return $response->json('candidates.0.content.parts.0.text', '');
    }

    public function getModel(): string
    {
        return $this->model;
    }
}
