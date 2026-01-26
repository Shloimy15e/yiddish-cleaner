<?php

namespace App\Services\Alignment\Drivers;

use App\Services\Alignment\AlignedWord;
use App\Services\Alignment\AlignmentDriverInterface;
use App\Services\Alignment\AlignmentResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Local Python WhisperX alignment driver.
 * 
 * Calls a local Python script to perform forced alignment using WhisperX.
 * Requires Python with whisperx package installed.
 */
class LocalWhisperXDriver implements AlignmentDriverInterface
{
    protected string $scriptPath;
    protected string $pythonPath;

    public function __construct(
        protected ?string $apiKey = null, // Not used for local execution
        protected string $model = 'large-v2',
    ) {
        $this->scriptPath = config('alignment.providers.local_whisperx.script_path', base_path('scripts/align.py'));
        $this->pythonPath = config('alignment.providers.local_whisperx.python_path', 'python');
    }

    public function align(string $audioPath, string $text, array $options = []): AlignmentResult
    {
        if (! file_exists($audioPath)) {
            throw new RuntimeException("Audio file not found: {$audioPath}");
        }

        if (empty(trim($text))) {
            throw new RuntimeException('Text to align cannot be empty');
        }

        if (! file_exists($this->scriptPath)) {
            throw new RuntimeException("Alignment script not found: {$this->scriptPath}. Run: pip install whisperx");
        }

        // Write text to temp file (handle unicode properly)
        $textPath = storage_path('app/temp/align_text_' . uniqid() . '.txt');
        $tempDir = dirname($textPath);
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        file_put_contents($textPath, $text);

        try {
            $language = $options['language'] ?? 'yi';
            $timeout = $options['timeout'] ?? 600;
            $device = config('alignment.providers.local_whisperx.device', 'auto');
            $computeType = config('alignment.providers.local_whisperx.compute_type', 'float16');

            $command = [
                $this->pythonPath,
                $this->scriptPath,
                $audioPath,
                $textPath,
                '--language', $language,
                '--model', $this->model,
                '--device', $device,
                '--compute-type', $computeType,
            ];

            Log::info('Running WhisperX alignment', [
                'audio' => basename($audioPath),
                'text_length' => strlen($text),
                'language' => $language,
                'model' => $this->model,
            ]);

            $result = Process::timeout($timeout)->run($command);

            if (! $result->successful()) {
                $error = $result->errorOutput();
                
                // Try to parse JSON error from stderr
                $errorData = json_decode($error, true);
                $errorMessage = $errorData['error'] ?? $error;
                
                Log::error('WhisperX alignment failed', [
                    'error' => $errorMessage,
                    'exit_code' => $result->exitCode(),
                ]);
                
                throw new RuntimeException("WhisperX alignment failed: {$errorMessage}");
            }

            $output = $result->output();
            $data = json_decode($output, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('Failed to parse alignment output: ' . json_last_error_msg());
            }

            if (isset($data['error'])) {
                throw new RuntimeException("Alignment error: {$data['error']}");
            }

            return $this->buildResult($data, $text);

        } finally {
            // Clean up temp file
            if (file_exists($textPath)) {
                unlink($textPath);
            }
        }
    }

    /**
     * Build alignment result from script output.
     */
    protected function buildResult(array $data, string $originalText): AlignmentResult
    {
        $words = [];

        foreach ($data['words'] ?? [] as $wordData) {
            $words[] = new AlignedWord(
                word: $wordData['word'] ?? '',
                start: (float) ($wordData['start'] ?? 0),
                end: (float) ($wordData['end'] ?? 0),
                confidence: isset($wordData['confidence']) ? (float) $wordData['confidence'] : null,
            );
        }

        return new AlignmentResult(
            text: $originalText,
            provider: 'local_whisperx',
            model: $this->model,
            words: $words,
            durationSeconds: $data['duration'] ?? null,
            metadata: [
                'language' => $data['language'] ?? null,
                'word_count' => $data['word_count'] ?? count($words),
            ],
        );
    }

    public function getProvider(): string
    {
        return 'local_whisperx';
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

    /**
     * Check if Python and WhisperX are available.
     */
    public static function isAvailable(): bool
    {
        try {
            $pythonPath = config('alignment.providers.local_whisperx.python_path', 'python');
            $result = Process::timeout(10)->run([$pythonPath, '-c', 'import whisperx; print("ok")']);
            return $result->successful() && str_contains($result->output(), 'ok');
        } catch (\Throwable $e) {
            return false;
        }
    }
}
