<?php

namespace App\Services\Asr\Drivers;

use App\Services\Asr\AsrDriverInterface;
use App\Services\Asr\AsrResult;
use App\Services\Asr\AsrSegment;
use App\Services\Asr\AsrWord;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

        // Submit transcription job with timestamps enabled
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

        // Enable timestamps for word-level data
        $response = $request->post("{$this->baseUrl}/transcriptions?timestamps=true");

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
            // Request with timestamps=true to get timestamped text
            $response = Http::withHeaders([
                'X-API-KEY' => $this->apiKey,
            ])->get("{$this->baseUrl}/transcriptions/{$jobId}?timestamps=true");

            if (! $response->successful()) {
                $error = $response->json('error.message', $response->body());
                throw new RuntimeException("YiddishLabs API error: {$error}");
            }

            $data = $response->json();

            if ($data['status'] === 'completed') {
                $text = $data['text'] ?? '';

                // Parse word and segment-level data from timestamped text
                // Wrapped in try-catch to ensure parsing failures don't crash transcription
                $words = null;
                $segments = null;
                $cleanText = $text;

                try {
                    $segments = $this->parseTimestampedTextAsSegments($text);

                    // Also get words for backward compatibility
                    $words = $this->parseTimestampedText($text);

                    // Get clean text (without timestamps) for storage
                    if ($segments !== null && count($segments) > 0) {
                        $cleanText = implode(' ', array_map(fn (AsrSegment $s) => $s->text, $segments));
                    } elseif ($words !== null && count($words) > 0) {
                        $cleanText = $this->getCleanText($words);
                    }
                } catch (\Throwable $e) {
                    // Log the parsing error but don't fail the transcription
                    Log::warning('YiddishLabs timestamp parsing failed, continuing without timing data', [
                        'error' => $e->getMessage(),
                        'job_id' => $jobId,
                        'text_preview' => substr($text, 0, 200),
                    ]);
                    $words = null;
                    $segments = null;
                    $cleanText = $text;
                }

                return new AsrResult(
                    text: $cleanText,
                    provider: 'yiddishlabs',
                    model: $this->model,
                    durationSeconds: $data['duration_seconds'] ?? null,
                    wordCount: $data['word_count'] ?? null,
                    summary: $data['summary'] ?? null,
                    keywords: $data['keywords'] ?? [],
                    metadata: [
                        'job_id' => $jobId,
                        'credits_cost' => $data['credits_cost'] ?? null,
                        'raw_timestamped_text' => $text,
                    ],
                    words: $words,
                    segments: $segments,
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

    /**
     * Parse timestamped text from YiddishLabs into segment-level data.
     *
     * Each timestamp marker becomes a segment containing the text until the next marker.
     *
     * @return AsrSegment[]|null
     */
    protected function parseTimestampedTextAsSegments(string $text): ?array
    {
        // Try format: [HH:MM:SS.mmm] or [MM:SS.mmm]
        $pattern1 = '/\[(\d{1,2}:)?(\d{1,2}):(\d{1,2})\.(\d{1,3})\]/';

        // Try format: <seconds.milliseconds>
        $pattern2 = '/<(\d+\.?\d*)>/';

        // Try format: (seconds.milliseconds)
        $pattern3 = '/\((\d+\.?\d*)\)/';

        $segments = [];

        if (preg_match($pattern1, $text)) {
            $segments = $this->parseSegmentsWithBracketTimestamps($text);
        } elseif (preg_match($pattern2, $text)) {
            $segments = $this->parseSegmentsWithAngleBracketTimestamps($text);
        } elseif (preg_match($pattern3, $text)) {
            $segments = $this->parseSegmentsWithParenTimestamps($text);
        }

        return count($segments) > 0 ? $segments : null;
    }

    /**
     * Parse text with [HH:MM:SS.mmm] timestamps into segments.
     *
     * @return AsrSegment[]
     */
    protected function parseSegmentsWithBracketTimestamps(string $text): array
    {
        $segments = [];
        $pattern = '/\[(?:(\d{1,2}):)?(\d{1,2}):(\d{1,2})\.(\d{1,3})\]\s*([^\[\]]+)/';

        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            for ($i = 0; $i < count($matches); $i++) {
                $hours = ! empty($matches[$i][1]) ? (int) $matches[$i][1] : 0;
                $minutes = (int) $matches[$i][2];
                $seconds = (int) $matches[$i][3];
                $milliseconds = (int) str_pad($matches[$i][4], 3, '0');

                $startTime = $hours * 3600 + $minutes * 60 + $seconds + $milliseconds / 1000;
                $segmentText = trim($matches[$i][5]);
                $wordsInSegment = preg_split('/\s+/', $segmentText, -1, PREG_SPLIT_NO_EMPTY);

                $endTime = isset($matches[$i + 1])
                    ? $this->parseTimestampToSeconds($matches[$i + 1])
                    : $startTime + (count($wordsInSegment) * 0.3);

                // Create embedded word timing
                $wordDuration = count($wordsInSegment) > 0
                    ? ($endTime - $startTime) / count($wordsInSegment)
                    : 0;

                $embeddedWords = [];
                foreach ($wordsInSegment as $j => $word) {
                    $wordStart = $startTime + ($j * $wordDuration);
                    $embeddedWords[] = [
                        'word' => $word,
                        'start' => $wordStart,
                        'end' => $wordStart + $wordDuration,
                    ];
                }

                $segments[] = new AsrSegment(
                    text: $segmentText,
                    start: $startTime,
                    end: $endTime,
                    confidence: null,
                    words: count($embeddedWords) > 0 ? $embeddedWords : null,
                );
            }
        }

        return $segments;
    }

    /**
     * Parse text with <seconds> timestamps into segments.
     *
     * @return AsrSegment[]
     */
    protected function parseSegmentsWithAngleBracketTimestamps(string $text): array
    {
        $segments = [];
        $pattern = '/<(\d+\.?\d*)>\s*([^<]+)/';

        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            for ($i = 0; $i < count($matches); $i++) {
                $startTime = (float) $matches[$i][1];
                $segmentText = trim($matches[$i][2]);
                $wordsInSegment = preg_split('/\s+/', $segmentText, -1, PREG_SPLIT_NO_EMPTY);

                $endTime = isset($matches[$i + 1])
                    ? (float) $matches[$i + 1][1]
                    : $startTime + (count($wordsInSegment) * 0.3);

                $wordDuration = count($wordsInSegment) > 0
                    ? ($endTime - $startTime) / count($wordsInSegment)
                    : 0;

                $embeddedWords = [];
                foreach ($wordsInSegment as $j => $word) {
                    $wordStart = $startTime + ($j * $wordDuration);
                    $embeddedWords[] = [
                        'word' => $word,
                        'start' => $wordStart,
                        'end' => $wordStart + $wordDuration,
                    ];
                }

                $segments[] = new AsrSegment(
                    text: $segmentText,
                    start: $startTime,
                    end: $endTime,
                    confidence: null,
                    words: count($embeddedWords) > 0 ? $embeddedWords : null,
                );
            }
        }

        return $segments;
    }

    /**
     * Parse text with (seconds) timestamps into segments.
     *
     * @return AsrSegment[]
     */
    protected function parseSegmentsWithParenTimestamps(string $text): array
    {
        $segments = [];
        $pattern = '/\((\d+\.?\d*)\)\s*([^()]+)/';

        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            for ($i = 0; $i < count($matches); $i++) {
                $startTime = (float) $matches[$i][1];
                $segmentText = trim($matches[$i][2]);
                $wordsInSegment = preg_split('/\s+/', $segmentText, -1, PREG_SPLIT_NO_EMPTY);

                $endTime = isset($matches[$i + 1])
                    ? (float) $matches[$i + 1][1]
                    : $startTime + (count($wordsInSegment) * 0.3);

                $wordDuration = count($wordsInSegment) > 0
                    ? ($endTime - $startTime) / count($wordsInSegment)
                    : 0;

                $embeddedWords = [];
                foreach ($wordsInSegment as $j => $word) {
                    $wordStart = $startTime + ($j * $wordDuration);
                    $embeddedWords[] = [
                        'word' => $word,
                        'start' => $wordStart,
                        'end' => $wordStart + $wordDuration,
                    ];
                }

                $segments[] = new AsrSegment(
                    text: $segmentText,
                    start: $startTime,
                    end: $endTime,
                    confidence: null,
                    words: count($embeddedWords) > 0 ? $embeddedWords : null,
                );
            }
        }

        return $segments;
    }

    /**
     * Parse timestamped text from YiddishLabs into word-level data (legacy).
     *
     * Expected format variations (to be confirmed with sample response):
     * - "[00:00:01.234] word1 word2 [00:00:02.567] word3"
     * - "<0.000> word1 <0.500> word2"
     * - Or other timestamp formats
     *
     * @return AsrWord[]|null
     */
    protected function parseTimestampedText(string $text): ?array
    {
        // Try format: [HH:MM:SS.mmm] or [MM:SS.mmm] or [SS.mmm]
        $pattern1 = '/\[(\d{1,2}:)?(\d{1,2}):(\d{1,2})\.(\d{1,3})\]/';

        // Try format: <seconds.milliseconds>
        $pattern2 = '/<(\d+\.?\d*)>/';

        // Try format: (seconds.milliseconds)
        $pattern3 = '/\((\d+\.?\d*)\)/';

        $words = [];
        $currentTime = 0.0;

        // Try pattern 1: [HH:MM:SS.mmm] or [MM:SS.mmm]
        if (preg_match($pattern1, $text)) {
            $parts = preg_split($pattern1, $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
            $words = $this->parseWithBracketTimestamps($text);
        }
        // Try pattern 2: <seconds>
        elseif (preg_match($pattern2, $text)) {
            $words = $this->parseWithAngleBracketTimestamps($text);
        }
        // Try pattern 3: (seconds)
        elseif (preg_match($pattern3, $text)) {
            $words = $this->parseWithParenTimestamps($text);
        }

        return count($words) > 0 ? $words : null;
    }

    /**
     * Parse text with [HH:MM:SS.mmm] or [MM:SS.mmm] timestamps.
     *
     * @return AsrWord[]
     */
    protected function parseWithBracketTimestamps(string $text): array
    {
        $words = [];
        $pattern = '/\[(?:(\d{1,2}):)?(\d{1,2}):(\d{1,2})\.(\d{1,3})\]\s*([^\[\]]+)/';

        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            for ($i = 0; $i < count($matches); $i++) {
                $hours = ! empty($matches[$i][1]) ? (int) $matches[$i][1] : 0;
                $minutes = (int) $matches[$i][2];
                $seconds = (int) $matches[$i][3];
                $milliseconds = (int) str_pad($matches[$i][4], 3, '0');

                $startTime = $hours * 3600 + $minutes * 60 + $seconds + $milliseconds / 1000;
                $wordsInSegment = preg_split('/\s+/', trim($matches[$i][5]), -1, PREG_SPLIT_NO_EMPTY);

                // Calculate end time from next timestamp or estimate
                $endTime = isset($matches[$i + 1])
                    ? $this->parseTimestampToSeconds($matches[$i + 1])
                    : $startTime + (count($wordsInSegment) * 0.3); // Estimate 0.3s per word

                // Distribute time evenly among words in segment
                $wordDuration = count($wordsInSegment) > 0
                    ? ($endTime - $startTime) / count($wordsInSegment)
                    : 0;

                foreach ($wordsInSegment as $j => $word) {
                    $wordStart = $startTime + ($j * $wordDuration);
                    $words[] = new AsrWord(
                        word: $word,
                        start: $wordStart,
                        end: $wordStart + $wordDuration,
                        confidence: null, // YiddishLabs doesn't provide confidence
                    );
                }
            }
        }

        return $words;
    }

    /**
     * Parse text with <seconds> timestamps.
     *
     * @return AsrWord[]
     */
    protected function parseWithAngleBracketTimestamps(string $text): array
    {
        $words = [];
        $pattern = '/<(\d+\.?\d*)>\s*([^<]+)/';

        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            for ($i = 0; $i < count($matches); $i++) {
                $startTime = (float) $matches[$i][1];
                $wordsInSegment = preg_split('/\s+/', trim($matches[$i][2]), -1, PREG_SPLIT_NO_EMPTY);

                $endTime = isset($matches[$i + 1])
                    ? (float) $matches[$i + 1][1]
                    : $startTime + (count($wordsInSegment) * 0.3);

                $wordDuration = count($wordsInSegment) > 0
                    ? ($endTime - $startTime) / count($wordsInSegment)
                    : 0;

                foreach ($wordsInSegment as $j => $word) {
                    $wordStart = $startTime + ($j * $wordDuration);
                    $words[] = new AsrWord(
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

    /**
     * Parse text with (seconds) timestamps.
     *
     * @return AsrWord[]
     */
    protected function parseWithParenTimestamps(string $text): array
    {
        $words = [];
        $pattern = '/\((\d+\.?\d*)\)\s*([^()]+)/';

        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            for ($i = 0; $i < count($matches); $i++) {
                $startTime = (float) $matches[$i][1];
                $wordsInSegment = preg_split('/\s+/', trim($matches[$i][2]), -1, PREG_SPLIT_NO_EMPTY);

                $endTime = isset($matches[$i + 1])
                    ? (float) $matches[$i + 1][1]
                    : $startTime + (count($wordsInSegment) * 0.3);

                $wordDuration = count($wordsInSegment) > 0
                    ? ($endTime - $startTime) / count($wordsInSegment)
                    : 0;

                foreach ($wordsInSegment as $j => $word) {
                    $wordStart = $startTime + ($j * $wordDuration);
                    $words[] = new AsrWord(
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

    /**
     * Parse a bracket timestamp match to seconds.
     */
    protected function parseTimestampToSeconds(array $match): float
    {
        $hours = ! empty($match[1]) ? (int) $match[1] : 0;
        $minutes = (int) $match[2];
        $seconds = (int) $match[3];
        $milliseconds = (int) str_pad($match[4], 3, '0');

        return $hours * 3600 + $minutes * 60 + $seconds + $milliseconds / 1000;
    }

    /**
     * Get clean text from parsed words (without timestamps).
     *
     * @param  AsrWord[]  $words
     */
    protected function getCleanText(array $words): string
    {
        return implode(' ', array_map(fn (AsrWord $w) => $w->word, $words));
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
