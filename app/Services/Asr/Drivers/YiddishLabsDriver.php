<?php

namespace App\Services\Asr\Drivers;

use App\Services\Asr\AsrDriverInterface;
use App\Services\Asr\AsrResult;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class YiddishLabsDriver implements AsrDriverInterface
{
    protected string $baseUrl = 'https://app.yiddishlabs.com/api/v1';

    public function __construct(
        protected ?string $apiKey,
        protected string $model = 'yiddish-libre',
    ) {}

    public function transcribe(string $audioPath, array $options = []): AsrResult
    {
        if (! $this->apiKey) {
            throw new RuntimeException('YiddishLabs API key not configured');
        }

        if (! file_exists($audioPath)) {
            throw new RuntimeException("Audio file not found: {$audioPath}");
        }

        // Submit transcription job
        $jobId = $this->submitJob($audioPath, $options);

        // Poll for completion
        return $this->pollForResult($jobId, $options['timeout'] ?? 600);
    }

    /**
     * Submit a transcription job.
     */
    protected function submitJob(string $audioPath, array $options): string
    {
        $request = Http::withHeaders([
            'X-API-KEY' => $this->apiKey,
        ])->attach(
            'file',
            file_get_contents($audioPath),
            basename($audioPath)
        );

        // Add optional parameters
        if (isset($options['name'])) {
            $request = $request->attach('name', $options['name']);
        }

        if (isset($options['context'])) {
            $request = $request->attach('context', $options['context']);
        }

        $response = $request->post("{$this->baseUrl}/transcriptions");

        if (! $response->successful()) {
            $error = $response->json('error.message', $response->body());
            throw new RuntimeException("YiddishLabs API error: {$error}");
        }

        $data = $response->json();

        if (empty($data['id'])) {
            throw new RuntimeException('YiddishLabs API did not return a job ID');
        }

        return $data['id'];
    }

    /**
     * Poll for transcription result with exponential backoff.
     */
    protected function pollForResult(string $jobId, int $timeout): AsrResult
    {
        $startTime = time();
        $pollInterval = 5; // Start with 5 seconds
        $maxInterval = 30; // Max 30 seconds between polls

        while (time() - $startTime < $timeout) {
            $response = Http::withHeaders([
                'X-API-KEY' => $this->apiKey,
            ])->get("{$this->baseUrl}/transcriptions/{$jobId}");

            if (! $response->successful()) {
                $error = $response->json('error.message', $response->body());
                throw new RuntimeException("YiddishLabs API error: {$error}");
            }

            $data = $response->json();

            if ($data['status'] === 'completed') {
                return new AsrResult(
                    text: $data['text'] ?? '',
                    provider: 'yiddishlabs',
                    model: $this->model,
                    durationSeconds: $data['duration_seconds'] ?? null,
                    wordCount: $data['word_count'] ?? null,
                    summary: $data['summary'] ?? null,
                    keywords: $data['keywords'] ?? [],
                    metadata: [
                        'job_id' => $jobId,
                        'credits_cost' => $data['credits_cost'] ?? null,
                    ],
                );
            }

            if ($data['status'] === 'failed') {
                throw new RuntimeException('YiddishLabs transcription failed: '.($data['error'] ?? 'Unknown error'));
            }

            // Exponential backoff
            sleep($pollInterval);
            $pollInterval = min($pollInterval * 1.5, $maxInterval);
        }

        throw new RuntimeException("YiddishLabs transcription timed out after {$timeout} seconds");
    }

    public function getProvider(): string
    {
        return 'yiddishlabs';
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function supportsAsync(): bool
    {
        return true;
    }
}
