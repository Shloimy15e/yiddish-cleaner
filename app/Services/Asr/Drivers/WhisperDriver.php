<?php

namespace App\Services\Asr\Drivers;

use App\Services\Asr\AsrDriverInterface;
use App\Services\Asr\AsrResult;
use App\Services\Asr\AsrWord;
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

        // Response format - always use verbose_json for word-level data
        $request = $request->attach('response_format', 'verbose_json');

        // Request word-level timestamps
        $request = $request->attach('timestamp_granularities[]', 'word');

        $response = $request->post("{$this->baseUrl}/audio/transcriptions");

        if (! $response->successful()) {
            $error = $response->json('error.message', $response->body());
            throw new RuntimeException("Whisper API error: {$error}");
        }

        $data = $response->json();

        // verbose_json format includes duration
        $text = $data['text'] ?? '';
        $duration = $data['duration'] ?? null;

        // Extract word-level data with timing and confidence
        $words = $this->extractWords($data);

        return new AsrResult(
            text: $text,
            provider: 'whisper',
            model: $this->model,
            durationSeconds: $duration,
            wordCount: str_word_count($text),
            metadata: [
                'language' => $data['language'] ?? $language,
            ],
            words: $words,
        );
    }

    /**
     * Extract word-level timing and confidence data from Whisper response.
     *
     * @return AsrWord[]|null
     */
    protected function extractWords(array $data): ?array
    {
        // Whisper returns words array when timestamp_granularities includes 'word'
        if (empty($data['words'])) {
            return null;
        }

        $words = [];
        foreach ($data['words'] as $wordData) {
            // Whisper word format: { word: string, start: float, end: float }
            // Confidence is not directly provided per word, but segments have avg_logprob
            // We'll approximate confidence from segment data if available
            $confidence = null;

            // If segments are available, try to find the segment containing this word
            // and use its avg_logprob to derive confidence
            if (! empty($data['segments'])) {
                $wordStart = $wordData['start'] ?? 0;
                foreach ($data['segments'] as $segment) {
                    if ($wordStart >= ($segment['start'] ?? 0) && $wordStart < ($segment['end'] ?? PHP_FLOAT_MAX)) {
                        // Convert log probability to confidence (0-1 scale)
                        // avg_logprob is typically negative, closer to 0 = higher confidence
                        if (isset($segment['avg_logprob'])) {
                            $confidence = $this->logProbToConfidence($segment['avg_logprob']);
                        }
                        break;
                    }
                }
            }

            $words[] = new AsrWord(
                word: $wordData['word'] ?? '',
                start: (float) ($wordData['start'] ?? 0),
                end: (float) ($wordData['end'] ?? 0),
                confidence: $confidence,
            );
        }

        return $words;
    }

    /**
     * Convert log probability to confidence score (0-1).
     * Whisper avg_logprob typically ranges from -1 (high confidence) to -3+ (low confidence).
     */
    protected function logProbToConfidence(float $logProb): float
    {
        // Clamp logprob to reasonable range
        $logProb = max(-3.0, min(0.0, $logProb));

        // Linear mapping: -3 -> 0, 0 -> 1
        return ($logProb + 3.0) / 3.0;
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
