<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiCredential;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class LlmController extends Controller
{
    /**
     * Get available LLM providers and their models.
     */
    public function providers(Request $request): JsonResponse
    {
        $user = $request->user();
        $providers = config('cleaning.llm_providers', []);

        $result = [];
        foreach ($providers as $name => $config) {
            // Check if user has credentials for this provider
            $hasCredential = ApiCredential::where('user_id', $user->id)
                ->where('provider', $name)
                ->where('type', 'llm')
                ->exists();

            $result[$name] = [
                'name' => ucfirst($name),
                'default_model' => $config['default_model'],
                'has_credential' => $hasCredential,
                'models' => $this->getStaticModels($name),
            ];
        }

        return response()->json($result);
    }

    /**
     * Fetch available models for a specific provider.
     */
    public function models(Request $request, string $provider): JsonResponse
    {
        $user = $request->user();
        $config = config("cleaning.llm_providers.{$provider}");

        if (!$config) {
            return response()->json(['error' => 'Unknown provider'], 404);
        }

        $credential = ApiCredential::where('user_id', $user->id)
            ->where('provider', $provider)
            ->where('type', 'llm')
            ->first();

        if (!$credential) {
            // Return static list if no credential
            return response()->json([
                'models' => $this->getStaticModels($provider),
                'default' => $config['default_model'],
                'source' => 'static',
            ]);
        }

        // Try to fetch models dynamically from provider API
        $models = $this->fetchModelsFromApi($provider, $credential->api_key, $config);

        return response()->json([
            'models' => $models ?: $this->getStaticModels($provider),
            'default' => $credential->default_model ?? $config['default_model'],
            'source' => $models ? 'api' : 'static',
        ]);
    }

    /**
     * Fetch models from provider API.
     */
    protected function fetchModelsFromApi(string $provider, string $apiKey, array $config): ?array
    {
        try {
            switch ($provider) {
                case 'openrouter':
                    return $this->fetchOpenRouterModels($apiKey);

                case 'openai':
                    return $this->fetchOpenAiModels($apiKey, $config['base_url'] ?? 'https://api.openai.com/v1');

                case 'anthropic':
                    // Anthropic doesn't have a models endpoint, use static list
                    return null;

                case 'google':
                    // Google Vertex AI models - use static list for now
                    return null;

                case 'groq':
                    return $this->fetchGroqModels($apiKey);

                default:
                    return null;
            }
        } catch (\Exception $e) {
            \Log::warning("Failed to fetch models from {$provider}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Fetch models from OpenRouter API.
     */
    protected function fetchOpenRouterModels(string $apiKey): array
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
        ])->get('https://openrouter.ai/api/v1/models');

        if (!$response->successful()) {
            return [];
        }

        $data = $response->json('data', []);

        return collect($data)
            ->filter(fn ($model) => !str_contains($model['id'] ?? '', ':free'))
            ->map(fn ($model) => [
                'id' => $model['id'],
                'name' => $model['name'] ?? $model['id'],
                'context_length' => $model['context_length'] ?? null,
                'pricing' => [
                    'prompt' => $model['pricing']['prompt'] ?? null,
                    'completion' => $model['pricing']['completion'] ?? null,
                ],
            ])
            ->sortBy('name')
            ->values()
            ->toArray();
    }

    /**
     * Fetch models from OpenAI API.
     */
    protected function fetchOpenAiModels(string $apiKey, string $baseUrl): array
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
        ])->get("{$baseUrl}/models");

        if (!$response->successful()) {
            return [];
        }

        $data = $response->json('data', []);

        return collect($data)
            ->filter(fn ($model) => str_starts_with($model['id'], 'gpt-'))
            ->map(fn ($model) => [
                'id' => $model['id'],
                'name' => $model['id'],
            ])
            ->sortByDesc('id')
            ->values()
            ->toArray();
    }

    /**
     * Fetch models from Groq API.
     */
    protected function fetchGroqModels(string $apiKey): array
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
        ])->get('https://api.groq.com/openai/v1/models');

        if (!$response->successful()) {
            return [];
        }

        $data = $response->json('data', []);

        return collect($data)
            ->map(fn ($model) => [
                'id' => $model['id'],
                'name' => $model['id'],
                'context_length' => $model['context_window'] ?? null,
            ])
            ->sortBy('name')
            ->values()
            ->toArray();
    }

    /**
     * Get static model list for a provider (fallback).
     */
    protected function getStaticModels(string $provider): array
    {
        $models = [
            'openrouter' => [
                ['id' => 'anthropic/claude-sonnet-4', 'name' => 'Claude Sonnet 4'],
                ['id' => 'anthropic/claude-3.5-sonnet', 'name' => 'Claude 3.5 Sonnet'],
                ['id' => 'anthropic/claude-3-haiku', 'name' => 'Claude 3 Haiku'],
                ['id' => 'openai/gpt-4o', 'name' => 'GPT-4o'],
                ['id' => 'openai/gpt-4o-mini', 'name' => 'GPT-4o Mini'],
                ['id' => 'google/gemini-pro-1.5', 'name' => 'Gemini 1.5 Pro'],
                ['id' => 'google/gemini-flash-1.5', 'name' => 'Gemini 1.5 Flash'],
                ['id' => 'meta-llama/llama-3.3-70b-instruct', 'name' => 'Llama 3.3 70B'],
                ['id' => 'deepseek/deepseek-chat', 'name' => 'DeepSeek Chat'],
            ],
            'openai' => [
                ['id' => 'gpt-4o', 'name' => 'GPT-4o'],
                ['id' => 'gpt-4o-mini', 'name' => 'GPT-4o Mini'],
                ['id' => 'gpt-4-turbo', 'name' => 'GPT-4 Turbo'],
                ['id' => 'gpt-4', 'name' => 'GPT-4'],
                ['id' => 'gpt-3.5-turbo', 'name' => 'GPT-3.5 Turbo'],
            ],
            'anthropic' => [
                ['id' => 'claude-sonnet-4-20250514', 'name' => 'Claude Sonnet 4'],
                ['id' => 'claude-3-5-sonnet-20241022', 'name' => 'Claude 3.5 Sonnet'],
                ['id' => 'claude-3-5-haiku-20241022', 'name' => 'Claude 3.5 Haiku'],
                ['id' => 'claude-3-opus-20240229', 'name' => 'Claude 3 Opus'],
                ['id' => 'claude-3-haiku-20240307', 'name' => 'Claude 3 Haiku'],
            ],
            'google' => [
                ['id' => 'gemini-1.5-pro', 'name' => 'Gemini 1.5 Pro'],
                ['id' => 'gemini-1.5-flash', 'name' => 'Gemini 1.5 Flash'],
                ['id' => 'gemini-1.5-flash-8b', 'name' => 'Gemini 1.5 Flash 8B'],
                ['id' => 'gemini-2.0-flash-exp', 'name' => 'Gemini 2.0 Flash'],
            ],
            'groq' => [
                ['id' => 'llama-3.3-70b-versatile', 'name' => 'Llama 3.3 70B'],
                ['id' => 'llama-3.1-70b-versatile', 'name' => 'Llama 3.1 70B'],
                ['id' => 'llama-3.1-8b-instant', 'name' => 'Llama 3.1 8B'],
                ['id' => 'mixtral-8x7b-32768', 'name' => 'Mixtral 8x7B'],
                ['id' => 'gemma2-9b-it', 'name' => 'Gemma 2 9B'],
            ],
        ];

        return $models[$provider] ?? [];
    }
}
