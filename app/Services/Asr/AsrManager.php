<?php

namespace App\Services\Asr;

use App\Models\ApiCredential;
use App\Services\Asr\Drivers\LocalWhisperDriver;
use App\Services\Asr\Drivers\WhisperDriver;
use App\Services\Asr\Drivers\YiddishLabsDriver;
use InvalidArgumentException;

class AsrManager
{
    protected array $drivers = [];

    /**
     * Get a driver instance for the given provider.
     */
    public function driver(string $provider, ?ApiCredential $credential = null, ?string $model = null): AsrDriverInterface
    {
        $config = config("asr.providers.{$provider}");

        if (! $config) {
            throw new InvalidArgumentException("Unknown ASR provider: {$provider}");
        }

        $key = $provider.($credential?->id ?? 'default').($model ?? '');

        if (! isset($this->drivers[$key])) {
            $driverClass = $config['driver'];

            // LocalWhisperDriver doesn't need an API key
            if ($provider === 'local-whisper') {
                $this->drivers[$key] = new $driverClass(
                    apiKey: null,
                    model: $model ?? $config['default_model'],
                    device: config('asr.local_whisper.device', 'cpu'),
                );
            } else {
                $this->drivers[$key] = new $driverClass(
                    apiKey: $credential?->api_key,
                    model: $model ?? $credential?->default_model ?? $config['default_model'],
                );
            }
        }

        return $this->drivers[$key];
    }

    /**
     * Transcribe audio using the specified provider.
     */
    public function transcribe(
        string $audioPath,
        string $provider,
        ?ApiCredential $credential = null,
        ?string $model = null,
        array $options = []
    ): AsrResult {
        $driver = $this->driver($provider, $credential, $model);

        return $driver->transcribe($audioPath, $options);
    }

    /**
     * Get available provider names.
     */
    public function getProviders(): array
    {
        return array_keys(config('asr.providers', []));
    }

    /**
     * Get provider configuration.
     */
    public function getProviderConfig(string $provider): ?array
    {
        return config("asr.providers.{$provider}");
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
     * Map of provider classes.
     */
    public static function getDriverClass(string $provider): string
    {
        return match ($provider) {
            'yiddishlabs' => YiddishLabsDriver::class,
            'whisper' => WhisperDriver::class,
            'local-whisper' => LocalWhisperDriver::class,
            default => throw new InvalidArgumentException("Unknown ASR provider: {$provider}"),
        };
    }
}
