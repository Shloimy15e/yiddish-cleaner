<?php

namespace App\Services\Llm;

use App\Models\ApiCredential;
use App\Services\Llm\Drivers\LlmDriverInterface;
use InvalidArgumentException;

class LlmManager
{
    protected array $drivers = [];

    /**
     * Get a driver instance for the given provider.
     */
    public function driver(string $provider, ?ApiCredential $credential = null): LlmDriverInterface
    {
        $config = config("cleaning.llm_providers.{$provider}");

        if (!$config) {
            throw new InvalidArgumentException("Unknown LLM provider: {$provider}");
        }

        $key = $provider . ($credential?->id ?? 'default');

        if (!isset($this->drivers[$key])) {
            $driverClass = $config['driver'];
            $this->drivers[$key] = new $driverClass(
                apiKey: $credential?->api_key,
                model: $credential?->default_model ?? $config['default_model'],
                baseUrl: $config['base_url'] ?? null,
            );
        }

        return $this->drivers[$key];
    }

    /**
     * Clean text using LLM.
     */
    public function clean(string $text, string $provider, ?ApiCredential $credential = null, ?string $prompt = null): string
    {
        $driver = $this->driver($provider, $credential);

        $prompt = $prompt ?? config('cleaning.default_llm_prompt');
        $prompt = str_replace('{document_text}', $text, $prompt);

        return $driver->complete($prompt);
    }

    /**
     * Get available provider names.
     */
    public function getProviders(): array
    {
        return array_keys(config('cleaning.llm_providers', []));
    }
}
