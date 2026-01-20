<?php

namespace App\Jobs;

use App\Models\AudioSample;
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
 * Clean an audio sample's transcript (async job for batch operations).
 *
 * For single sample cleaning, use CleaningService::cleanSample() directly.
 */
class CleanAudioSampleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        public AudioSample $audioSample,
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
        try {
            $this->audioSample->update(['status' => AudioSample::STATUS_CLEANING]);

            $text = $this->audioSample->reference_text_raw;

            if (! $text) {
                throw new \RuntimeException('No raw text to clean');
            }

            if ($this->mode === 'llm') {
                $result = $this->cleanWithLlm($llm, $text);
            } else {
                $result = $cleaner->cleanWithPreset($text, $this->preset);
            }

            $cleanRate = $calculator->calculate($result);

            // Clear any existing cleaned transcript media
            $this->audioSample->clearMediaCollection('cleaned_transcript');

            // Save cleaned transcript as a text file via media library
            $cleanedFilePath = storage_path('app/temp/cleaned_'.$this->audioSample->id.'.txt');
            file_put_contents($cleanedFilePath, $result->cleanedText);
            $this->audioSample->addMedia($cleanedFilePath)
                ->usingFileName('cleaned_transcript.txt')
                ->toMediaCollection('cleaned_transcript');

            // Update sample with cleaning results
            $this->audioSample->update([
                'reference_text_clean' => $result->cleanedText,
                'reference_hash_clean' => $result->getCleanedHash(),
                'clean_rate' => $cleanRate->score,
                'clean_rate_category' => $cleanRate->category,
                'metrics' => $result->getMetrics(),
                'removals' => $result->removals,
                'status' => AudioSample::STATUS_CLEANED,
                // Reset validation when re-cleaning
                'validated_at' => null,
                'validated_by' => null,
                'review_notes' => null,
            ]);

        } catch (Throwable $e) {
            $this->audioSample->update([
                'status' => AudioSample::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Clean text using LLM provider.
     */
    protected function cleanWithLlm(LlmManager $llm, string $text): CleaningResult
    {
        $user = $this->userId
            ? \App\Models\User::find($this->userId)
            : $this->audioSample->processingRun?->user;

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
        $this->audioSample->update([
            'status' => AudioSample::STATUS_FAILED,
            'error_message' => $exception->getMessage(),
        ]);
    }
}
