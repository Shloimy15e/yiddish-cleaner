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

        $requestData = [
            'model' => $options['model'] ?? $this->model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => $options['temperature'] ?? 0.3,
        ];

        // Only set max_tokens if explicitly provided, otherwise let model use its full capacity
        if (isset($options['max_tokens'])) {
            $requestData['max_tokens'] = $options['max_tokens'];
        }

        // Use streaming to avoid timeout issues on long responses
        $requestData['stream'] = true;

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
            'Content-Type' => 'application/json',
            'HTTP-Referer' => config('app.url', 'http://localhost'),
            'X-Title' => config('app.name', 'Yiddish Cleaner'),
        ])->connectTimeout(30)->timeout(900)->withOptions([
            'stream' => true,
        ])->post("{$this->baseUrl}/chat/completions", $requestData);

        if (! $response->successful()) {
            throw new RuntimeException(
                'OpenRouter API error: '.$response->body()
            );
        }

        // Parse Server-Sent Events (SSE) stream and concatenate content
        $content = '';
        $body = $response->body();
        
        foreach (explode("\n", $body) as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'data: ')) {
                $data = substr($line, 6);
                if ($data === '[DONE]') {
                    break;
                }
                $json = json_decode($data, true);
                if (isset($json['choices'][0]['delta']['content'])) {
                    $content .= $json['choices'][0]['delta']['content'];
                }
            }
        }

        return $content;
    }

    public function getModel(): string
    {
        return $this->model;
    }
}
