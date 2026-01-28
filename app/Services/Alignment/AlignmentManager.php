<?php

namespace App\Services\Alignment;

use App\Models\ApiCredential;
use App\Services\Alignment\Drivers\LocalWhisperXDriver;
use App\Services\Alignment\Drivers\WhisperXDriver;
use InvalidArgumentException;

/**
 * Manager for forced alignment services.
 * 
 * Handles multiple alignment providers and model selection.
 */
class AlignmentManager
{
    protected array $drivers = [];

    /**
     * Get a driver instance for the given provider.
     */
    public function driver(string $provider, ?ApiCredential $credential = null, ?string $model = null): AlignmentDriverInterface
    {
        $config = config("alignment.providers.{$provider}");

        if (! $config) {
            throw new InvalidArgumentException("Unknown alignment provider: {$provider}");
        }

        $key = $provider . ($credential?->id ?? 'default') . ($model ?? '');

        if (! isset($this->drivers[$key])) {
            $driverClass = $config['driver'];
            $this->drivers[$key] = new $driverClass(
                apiKey: $credential?->api_key,
                model: $model ?? $credential?->default_model ?? $config['default_model'],
            );
        }

        return $this->drivers[$key];
    }

    /**
     * Align text to audio using the specified provider.
     */
    public function align(
        string $audioPath,
        string $text,
        string $provider,
        ?ApiCredential $credential = null,
        ?string $model = null,
        array $options = []
    ): AlignmentResult {
        $driver = $this->driver($provider, $credential, $model);

        return $driver->align($audioPath, $text, $options);
    }

    /**
     * Get available provider names.
     */
    public function getProviders(): array
    {
        return array_keys(config('alignment.providers', []));
    }

    /**
     * Get provider configuration.
     */
    public function getProviderConfig(string $provider): ?array
    {
        return config("alignment.providers.{$provider}");
    }

    /**
     * Get models available for a provider.
     */
    public function getModels(string $provider): array
    {
        $config = $this->getProviderConfig($provider);

        return $config['models'] ?? [$config['default_model'] ?? $provider];
    }

    /**
     * Check if a provider is available.
     */
    public function hasProvider(string $provider): bool
    {
        return config("alignment.providers.{$provider}") !== null;
    }

    /**
     * Check if a provider requires API credentials.
     */
    public function requiresCredential(string $provider): bool
    {
        $config = $this->getProviderConfig($provider);
        return $config['requires_credential'] ?? true;
    }

    /**
     * Get the default alignment provider.
     */
    public function getDefaultProvider(): string
    {
        return config('alignment.default', 'local_whisperx');
    }

    /**
     * Map of provider classes.
     */
    public static function getDriverClass(string $provider): string
    {
        return match ($provider) {
            'local_whisperx' => LocalWhisperXDriver::class,
            'whisperx' => WhisperXDriver::class,
            default => throw new InvalidArgumentException("Unknown alignment provider: {$provider}"),
        };
    }
}
