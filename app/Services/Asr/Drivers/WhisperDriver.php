<?php

namespace App\Services\Asr\Drivers;

use App\Services\Asr\AsrDriverInterface;
use App\Services\Asr\AsrResult;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WhisperDriver implements AsrDriverInterface
{
    protected string $baseUrl = 'https://api.openai.com/v1';

    public function __construct(
        protected ?string $apiKey,
        protected string $model = 'whisper-1',
    ) {}

    public function transcribe(string $audioPath, array $options = []): AsrResult
    {
        if (! $this->apiKey) {
            throw new RuntimeException('OpenAI API key not configured');
        }

        if (! file_exists($audioPath)) {
            throw new RuntimeException("Audio file not found: {$audioPath}");
        }

        // Check file size (max 25MB for Whisper)
        $fileSize = filesize($audioPath);
        if ($fileSize > 25 * 1024 * 1024) {
            throw new RuntimeException('Audio file exceeds 25MB limit for Whisper API');
        }

        $request = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
        ])->timeout(600)->attach(
            'file',
            file_get_contents($audioPath),
            basename($audioPath)
        )->attach('model', $this->model);

        // Add language hint for Yiddish
        $language = $options['language'] ?? 'yi';
        $request = $request->attach('language', $language);

        // Response format
        $responseFormat = $options['response_format'] ?? 'verbose_json';
        $request = $request->attach('response_format', $responseFormat);

        $response = $request->post("{$this->baseUrl}/audio/transcriptions");

        if (! $response->successful()) {
            $error = $response->json('error.message', $response->body());
            throw new RuntimeException("Whisper API error: {$error}");
        }

        $data = $response->json();

        // verbose_json format includes duration
        $text = $data['text'] ?? '';
        $duration = $data['duration'] ?? null;

        return new AsrResult(
            text: $text,
            provider: 'whisper',
            model: $this->model,
            durationSeconds: $duration,
            wordCount: str_word_count($text),
            metadata: [
                'language' => $data['language'] ?? $language,
            ],
        );
    }

    public function getProvider(): string
    {
        return 'whisper';
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function supportsAsync(): bool
    {
        return false; // Whisper is synchronous only
    }
}
