<?php

namespace App\Jobs;

use App\Models\Transcription;
use App\Models\User;
use App\Services\Cleaning\CleanerService;
use App\Services\Cleaning\CleaningResult;
use App\Services\Cleaning\CleanRateCalculator;
use App\Services\Llm\LlmManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Clean a base transcription's text (async job).
 */
class CleanTranscriptionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        public int $transcriptionId,
        public string $preset = 'titles_only',
        public string $mode = 'rule',
        public ?string $llmProvider = null,
        public ?string $llmModel = null,
        public ?int $userId = null,
    ) {}

    public function handle(
        CleanerService $cleaner,
        CleanRateCalculator $calculator,
        LlmManager $llm,
    ): void {
        $transcription = Transcription::findOrFail($this->transcriptionId);

        if (! $transcription->isBase()) {
            throw new \RuntimeException('Only base transcriptions can be cleaned');
        }

        try {
            $transcription->update(['status' => Transcription::STATUS_PROCESSING]);

            $text = $transcription->text_raw;

            if (! $text) {
                throw new \RuntimeException('No raw text to clean');
            }

            if ($this->mode === 'llm') {
                $result = $this->cleanWithLlm($llm, $transcription, $text);
            } else {
                $result = $cleaner->cleanWithPreset($text, $this->preset);
            }

            $cleanRate = $calculator->calculate($result);

            // Clear any existing cleaned file
            $transcription->clearMediaCollection('cleaned_file');

            // Save cleaned text as a file via media library
            $cleanedFilePath = storage_path('app/temp/cleaned_transcription_' . $transcription->id . '.txt');
            if (! is_dir(dirname($cleanedFilePath))) {
                mkdir(dirname($cleanedFilePath), 0755, true);
            }
            file_put_contents($cleanedFilePath, $result->cleanedText);
            $transcription->addMedia($cleanedFilePath)
                ->usingFileName('cleaned.txt')
                ->toMediaCollection('cleaned_file');

            // Update transcription with cleaning results
            $transcription->update([
                'text_clean' => $result->cleanedText,
                'hash_clean' => $result->getCleanedHash(),
                'clean_rate' => $cleanRate->score,
                'clean_rate_category' => $cleanRate->category,
                'metrics' => $result->getMetrics(),
                'removals' => $result->removals,
                'cleaning_preset' => $this->preset,
                'cleaning_mode' => $this->mode,
                'status' => Transcription::STATUS_COMPLETED,
                // Reset validation when re-cleaning
                'validated_at' => null,
                'validated_by' => null,
                'review_notes' => null,
            ]);

            // Sync linked audio sample status
            if ($transcription->isLinked()) {
                $transcription->audioSample->syncStatusFromBaseTranscription();
            }

        } catch (Throwable $e) {
            $transcription->update([
                'status' => Transcription::STATUS_FAILED,
            ]);

            throw $e;
        }
    }

    /**
     * Clean text using LLM provider.
     */
    protected function cleanWithLlm(LlmManager $llm, Transcription $transcription, string $text): CleaningResult
    {
        $user = $this->userId
            ? User::find($this->userId)
            : $transcription->audioSample?->processingRun?->user;

        if (! $user) {
            throw new \RuntimeException('No user context for LLM cleaning');
        }

        $provider = $this->llmProvider ?? 'openrouter';
        $model = $this->llmModel ?? 'anthropic/claude-sonnet-4';
        $credential = $user->getApiCredential($provider, 'llm');

        if (! $credential) {
            throw new \RuntimeException("No API key configured for {$provider}");
        }

        $cleanedText = $llm->clean($text, $provider, $credential, $model);

        return new CleaningResult(
            originalText: $text,
            cleanedText: $cleanedText,
            removals: [],
            processorResults: ['llm' => ['changes' => 1, 'removals' => []]],
        );
    }

    public function failed(Throwable $exception): void
    {
        $transcription = Transcription::find($this->transcriptionId);

        if ($transcription) {
            $transcription->update([
                'status' => Transcription::STATUS_FAILED,
            ]);
        }
    }
}
