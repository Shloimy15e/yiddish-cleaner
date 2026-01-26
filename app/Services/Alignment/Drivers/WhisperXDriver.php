<?php

namespace App\Services\Alignment\Drivers;

use App\Services\Alignment\AlignedWord;
use App\Services\Alignment\AlignmentDriverInterface;
use App\Services\Alignment\AlignmentResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * WhisperX forced alignment driver.
 * 
 * Uses a WhisperX-compatible API for forced alignment.
 * This works with self-hosted WhisperX servers or compatible cloud APIs.
 * 
 * WhisperX provides high-quality word-level alignment using wav2vec2 models.
 * @see https://github.com/m-bain/whisperX
 */
class WhisperXDriver implements AlignmentDriverInterface
{
    protected string $baseUrl;

    public function __construct(
        protected ?string $apiKey,
        protected string $model = 'wav2vec2-large',
        ?string $baseUrl = null,
    ) {
        $this->baseUrl = $baseUrl ?? config('alignment.providers.whisperx.base_url', 'http://localhost:8000');
    }

    public function align(string $audioPath, string $text, array $options = []): AlignmentResult
    {
        if (! file_exists($audioPath)) {
            throw new RuntimeException("Audio file not found: {$audioPath}");
        }

        if (empty(trim($text))) {
            throw new RuntimeException('Text to align cannot be empty');
        }

        $request = Http::timeout($options['timeout'] ?? 300);

        // Add API key if configured
        if ($this->apiKey) {
            $request = $request->withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
            ]);
        }

        // Attach audio file and text
        $request = $request->attach(
            'audio',
            file_get_contents($audioPath),
            basename($audioPath)
        )->attach('text', $text);

        // Add language hint
        $language = $options['language'] ?? 'yi'; // Default to Yiddish
        $request = $request->attach('language', $language);

        // Add model selection if supported
        if ($this->model) {
            $request = $request->attach('model', $this->model);
        }

        Log::info('Submitting WhisperX alignment request', [
            'audio_file' => basename($audioPath),
            'text_length' => strlen($text),
            'language' => $language,
        ]);

        $response = $request->post("{$this->baseUrl}/align");

        if (! $response->successful()) {
            $error = $response->json('error', $response->json('detail', $response->body()));
            throw new RuntimeException("WhisperX alignment API error: " . (is_array($error) ? json_encode($error) : $error));
        }

        $data = $response->json();

        return $this->buildResult($data, $text);
    }

    /**
     * Build alignment result from API response.
     */
    protected function buildResult(array $data, string $originalText): AlignmentResult
    {
        $words = [];

        // WhisperX returns word-level alignment in 'word_segments' or 'words'
        $wordData = $data['word_segments'] ?? $data['words'] ?? [];

        foreach ($wordData as $item) {
            // Handle different response formats
            $word = $item['word'] ?? $item['text'] ?? '';
            $start = $item['start'] ?? $item['start_time'] ?? null;
            $end = $item['end'] ?? $item['end_time'] ?? null;

            // Skip words without timing
            if ($start === null || $end === null) {
                continue;
            }

            $words[] = new AlignedWord(
                word: $word,
                start: (float) $start,
                end: (float) $end,
                confidence: isset($item['score']) ? (float) $item['score'] : (isset($item['confidence']) ? (float) $item['confidence'] : null),
            );
        }

        // Fallback: parse segments if no word-level data
        if (empty($words) && isset($data['segments'])) {
            $words = $this->parseSegments($data['segments']);
        }

        return new AlignmentResult(
            text: $data['text'] ?? $originalText,
            provider: 'whisperx',
            model: $this->model,
            words: $words,
            durationSeconds: $data['duration'] ?? null,
            metadata: [
                'language' => $data['language'] ?? null,
                'alignment_model' => $data['alignment_model'] ?? $this->model,
            ],
        );
    }

    /**
     * Parse segments into words.
     *
     * @return AlignedWord[]
     */
    protected function parseSegments(array $segments): array
    {
        $words = [];

        foreach ($segments as $segment) {
            // Check if segment has word-level data
            if (isset($segment['words']) && is_array($segment['words'])) {
                foreach ($segment['words'] as $wordItem) {
                    if (! isset($wordItem['start']) || ! isset($wordItem['end'])) {
                        continue;
                    }

                    $words[] = new AlignedWord(
                        word: $wordItem['word'] ?? $wordItem['text'] ?? '',
                        start: (float) $wordItem['start'],
                        end: (float) $wordItem['end'],
                        confidence: isset($wordItem['score']) ? (float) $wordItem['score'] : null,
                    );
                }
            } else {
                // Segment doesn't have word-level data, estimate timing
                $segmentStart = (float) ($segment['start'] ?? 0);
                $segmentEnd = (float) ($segment['end'] ?? 0);
                $segmentText = $segment['text'] ?? '';

                $segmentWords = preg_split('/\s+/', trim($segmentText), -1, PREG_SPLIT_NO_EMPTY);

                if (empty($segmentWords)) {
                    continue;
                }

                $wordDuration = ($segmentEnd - $segmentStart) / count($segmentWords);

                foreach ($segmentWords as $i => $word) {
                    $wordStart = $segmentStart + ($i * $wordDuration);
                    $words[] = new AlignedWord(
                        word: $word,
                        start: $wordStart,
                        end: $wordStart + $wordDuration,
                        confidence: null,
                    );
                }
            }
        }

        return $words;
    }

    public function getProvider(): string
    {
        return 'whisperx';
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function supportsAsync(): bool
    {
        return false;
    }

    public function supportsBatch(): bool
    {
        return false;
    }
}
