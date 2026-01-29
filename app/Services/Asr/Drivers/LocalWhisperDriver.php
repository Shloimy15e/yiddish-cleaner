<?php

namespace App\Services\Asr\Drivers;

use App\Services\Asr\AsrDriverInterface;
use App\Services\Asr\AsrResult;
use App\Services\Asr\AsrSegment;
use App\Services\Asr\AsrWord;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Local Whisper driver using OpenAI's open-source Whisper model.
 * Runs completely locally without any API keys required.
 *
 * Requires the openai-whisper Python package:
 * pip install openai-whisper
 */
class LocalWhisperDriver implements AsrDriverInterface
{
    /**
     * Available model sizes from smallest to largest.
     */
    public const MODELS = [
        'tiny' => 'tiny',
        'base' => 'base',
        'small' => 'small',
        'medium' => 'medium',
        'large' => 'large-v3',
        'large-v1' => 'large-v1',
        'large-v2' => 'large-v2',
        'large-v3' => 'large-v3',
        'turbo' => 'large-v3-turbo',
    ];

    protected string $pythonPath;

    protected string $scriptPath;

    public function __construct(
        protected ?string $apiKey = null, // Not used, but kept for interface compatibility
        protected string $model = 'base',
        protected ?string $device = null, // 'cpu', 'cuda', or null for auto
    ) {
        $this->pythonPath = config('asr.local_whisper.python_path', 'python3');
        $this->scriptPath = base_path('scripts/whisper_transcribe.py');
    }

    public function transcribe(string $audioPath, array $options = []): AsrResult
    {
        if (! file_exists($audioPath)) {
            throw new RuntimeException("Audio file not found: {$audioPath}");
        }

        // Ensure the Python script exists
        $this->ensureScriptExists();

        // Build the command
        $modelName = self::MODELS[$this->model] ?? $this->model;
        $language = $options['language'] ?? 'yi'; // Default to Yiddish
        $device = $this->device ?? ($options['device'] ?? 'cpu');

        $command = sprintf(
            '%s %s --audio %s --model %s --language %s --device %s --output json 2>&1',
            escapeshellcmd($this->pythonPath),
            escapeshellarg($this->scriptPath),
            escapeshellarg($audioPath),
            escapeshellarg($modelName),
            escapeshellarg($language),
            escapeshellarg($device)
        );

        Log::debug('Local Whisper command', ['command' => $command]);

        // Execute the transcription
        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        $outputStr = implode("\n", $output);

        if ($returnCode !== 0) {
            Log::error('Local Whisper transcription failed', [
                'return_code' => $returnCode,
                'output' => $outputStr,
            ]);
            throw new RuntimeException("Local Whisper transcription failed: {$outputStr}");
        }

        // Find the JSON output (last line should be JSON)
        $jsonLine = null;
        foreach (array_reverse($output) as $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}')) {
                $jsonLine = $trimmed;
                break;
            }
        }

        if (! $jsonLine) {
            throw new RuntimeException("Local Whisper did not return valid JSON output: {$outputStr}");
        }

        $data = json_decode($jsonLine, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Failed to parse Local Whisper JSON output: '.json_last_error_msg());
        }

        if (isset($data['error'])) {
            throw new RuntimeException("Local Whisper error: {$data['error']}");
        }

        $text = $data['text'] ?? '';
        $duration = $data['duration'] ?? null;
        $detectedLanguage = $data['language'] ?? $language;

        // Extract segment-level data (primary)
        $segments = null;
        try {
            $segments = $this->extractSegments($data);
        } catch (\Throwable $e) {
            Log::warning('Local Whisper segment extraction failed, continuing without segment-level data', [
                'error' => $e->getMessage(),
                'text_preview' => substr($text, 0, 200),
            ]);
        }

        // Extract word-level data (legacy)
        $words = null;
        try {
            $words = $this->extractWords($data);
        } catch (\Throwable $e) {
            Log::warning('Local Whisper word extraction failed, continuing without word-level data', [
                'error' => $e->getMessage(),
                'text_preview' => substr($text, 0, 200),
            ]);
        }

        return new AsrResult(
            text: $text,
            provider: 'local-whisper',
            model: $modelName,
            durationSeconds: $duration,
            wordCount: str_word_count($text),
            metadata: [
                'language' => $detectedLanguage,
                'device' => $device,
            ],
            words: $words,
            segments: $segments,
        );
    }

    /**
     * Extract segment-level timing and confidence data from Whisper response.
     *
     * @return AsrSegment[]|null
     */
    protected function extractSegments(array $data): ?array
    {
        if (empty($data['segments'])) {
            return null;
        }

        $segments = [];

        foreach ($data['segments'] as $segmentData) {
            $segmentStart = (float) ($segmentData['start'] ?? 0);
            $segmentEnd = (float) ($segmentData['end'] ?? 0);

            // Convert avg_logprob to confidence score (0-1)
            $confidence = null;
            if (isset($segmentData['avg_logprob'])) {
                $confidence = $this->logProbToConfidence($segmentData['avg_logprob']);
            }

            // Extract embedded word timing from segment
            $segmentWords = [];
            foreach ($segmentData['words'] ?? [] as $wordData) {
                $segmentWords[] = [
                    'word' => trim($wordData['word'] ?? ''),
                    'start' => (float) ($wordData['start'] ?? 0),
                    'end' => (float) ($wordData['end'] ?? 0),
                    'probability' => $wordData['probability'] ?? null,
                ];
            }

            $segments[] = new AsrSegment(
                text: trim($segmentData['text'] ?? ''),
                start: $segmentStart,
                end: $segmentEnd,
                confidence: $confidence,
                words: count($segmentWords) > 0 ? $segmentWords : null,
            );
        }

        return count($segments) > 0 ? $segments : null;
    }

    /**
     * Extract word-level timing data from Whisper response (legacy).
     *
     * @return AsrWord[]|null
     */
    protected function extractWords(array $data): ?array
    {
        if (empty($data['words'])) {
            return null;
        }

        $words = [];
        $segments = $data['segments'] ?? [];

        foreach ($data['words'] as $wordData) {
            $confidence = null;

            // Try to find confidence from segment data
            if (! empty($segments)) {
                $wordStart = $wordData['start'] ?? 0;
                foreach ($segments as $segment) {
                    if ($wordStart >= ($segment['start'] ?? 0) && $wordStart < ($segment['end'] ?? PHP_FLOAT_MAX)) {
                        if (isset($segment['avg_logprob'])) {
                            $confidence = $this->logProbToConfidence($segment['avg_logprob']);
                        }
                        break;
                    }
                }
            }

            // Use word-level probability if available
            if (isset($wordData['probability'])) {
                $confidence = $wordData['probability'];
            }

            $words[] = new AsrWord(
                word: trim($wordData['word'] ?? ''),
                start: (float) ($wordData['start'] ?? 0),
                end: (float) ($wordData['end'] ?? 0),
                confidence: $confidence,
            );
        }

        return $words;
    }

    /**
     * Convert log probability to confidence score (0-1).
     */
    protected function logProbToConfidence(float $logProb): float
    {
        $logProb = max(-3.0, min(0.0, $logProb));

        return ($logProb + 3.0) / 3.0;
    }

    /**
     * Ensure the Python transcription script exists.
     */
    protected function ensureScriptExists(): void
    {
        if (! file_exists($this->scriptPath)) {
            $scriptDir = dirname($this->scriptPath);
            if (! is_dir($scriptDir)) {
                mkdir($scriptDir, 0755, true);
            }

            // Create the script
            $script = $this->getPythonScript();
            file_put_contents($this->scriptPath, $script);
            chmod($this->scriptPath, 0755);
        }
    }

    /**
     * Get the Python transcription script content.
     */
    protected function getPythonScript(): string
    {
        return <<<'PYTHON'
#!/usr/bin/env python3
"""
Local Whisper transcription script for yiddish-cleaner.
Outputs JSON with word-level timestamps.
"""

import argparse
import json
import sys
import warnings

# Suppress warnings for cleaner output
warnings.filterwarnings("ignore")

def main():
    parser = argparse.ArgumentParser(description='Transcribe audio using local Whisper')
    parser.add_argument('--audio', required=True, help='Path to audio file')
    parser.add_argument('--model', default='base', help='Whisper model size')
    parser.add_argument('--language', default='yi', help='Language code (yi for Yiddish)')
    parser.add_argument('--device', default='cpu', help='Device to use (cpu or cuda)')
    parser.add_argument('--output', default='json', help='Output format')
    
    args = parser.parse_args()
    
    try:
        import whisper
    except ImportError:
        print(json.dumps({"error": "openai-whisper not installed. Run: pip install openai-whisper"}))
        sys.exit(1)
    
    try:
        # Load the model
        model = whisper.load_model(args.model, device=args.device)
        
        # Transcribe with word timestamps
        result = model.transcribe(
            args.audio,
            language=args.language,
            word_timestamps=True,
            verbose=False
        )
        
        # Extract word-level data
        words = []
        for segment in result.get('segments', []):
            for word_info in segment.get('words', []):
                words.append({
                    'word': word_info.get('word', ''),
                    'start': word_info.get('start', 0),
                    'end': word_info.get('end', 0),
                    'probability': word_info.get('probability', None)
                })
        
        # Build output
        output = {
            'text': result.get('text', ''),
            'language': result.get('language', args.language),
            'duration': result.get('duration', None),
            'segments': result.get('segments', []),
            'words': words
        }
        
        print(json.dumps(output))
        
    except Exception as e:
        print(json.dumps({"error": str(e)}))
        sys.exit(1)

if __name__ == '__main__':
    main()
PYTHON;
    }

    public function getProvider(): string
    {
        return 'local-whisper';
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function supportsAsync(): bool
    {
        return false;
    }
}
