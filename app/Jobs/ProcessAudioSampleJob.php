<?php

namespace App\Jobs;

use App\Events\AudioSampleProcessed;
use App\Models\AudioSample;
use App\Services\Cleaning\CleanerService;
use App\Services\Cleaning\CleanRateCalculator;
use App\Services\Document\ParserService;
use App\Services\Llm\LlmManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessAudioSampleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        public AudioSample $audioSample,
        public ?string $filePath = null,
    ) {}

    public function handle(
        ParserService $parser,
        CleanerService $cleaner,
        CleanRateCalculator $calculator,
        LlmManager $llm,
    ): void {
        $run = $this->audioSample->processingRun;

        try {
            $this->audioSample->update(['status' => 'processing']);

            // Save the reference transcript file to media collection if provided
            if ($this->filePath && file_exists($this->filePath)) {
                $this->audioSample->addMedia($this->filePath)
                    ->preservingOriginal() // Keep file for text extraction
                    ->toMediaCollection('reference_transcript');
            }

            $text = $this->filePath
                ? $parser->extractText($this->filePath)
                : $this->audioSample->reference_text_raw;

            if (! $text) {
                throw new \RuntimeException('No text to process');
            }

            $this->audioSample->update(['reference_text_raw' => $text]);

            if ($run->mode === 'llm') {
                $user = $run->user;
                $provider = $run->llm_provider ?? 'openrouter';
                $model = $run->llm_model ?? 'anthropic/claude-sonnet-4';
                $credential = $user->getApiCredential($provider, 'llm');

                if (! $credential) {
                    throw new \RuntimeException("No API key configured for {$provider}");
                }

                $cleanedText = $llm->clean($text, $provider, $credential, $model);

                $result = new \App\Services\Cleaning\CleaningResult(
                    originalText: $text,
                    cleanedText: $cleanedText,
                    removals: [],
                    processorResults: ['llm' => ['changes' => 1, 'removals' => []]],
                );
            } else {
                $result = $cleaner->cleanWithPreset($text, $run->preset);
            }

            $cleanRate = $calculator->calculate($result);

            $this->audioSample->update([
                'reference_text_raw' => $result->originalText,
                'reference_text_clean' => $result->cleanedText,
                'reference_hash_raw' => $result->getOriginalHash(),
                'reference_hash_clean' => $result->getCleanedHash(),
                'clean_rate' => $cleanRate->score,
                'clean_rate_category' => $cleanRate->category,
                'metrics' => $result->getMetrics(),
                'removals' => $result->removals,
                'status' => 'completed',
            ]);

            // Save cleaned transcript as a text file
            $cleanedFilePath = storage_path('app/temp/cleaned_' . $this->audioSample->id . '.txt');
            file_put_contents($cleanedFilePath, $result->cleanedText);
            $this->audioSample->addMedia($cleanedFilePath)
                ->usingFileName('cleaned_transcript.txt')
                ->toMediaCollection('cleaned_transcript');

            $run->incrementCompleted();

            if ($this->filePath) {
                $parser->cleanup($this->filePath);
            }

        } catch (Throwable $e) {
            $this->audioSample->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            $run->incrementFailed();
        }

        event(new AudioSampleProcessed($run->fresh(), $this->audioSample->fresh()));
    }

    public function failed(Throwable $exception): void
    {
        $this->audioSample->update([
            'status' => 'failed',
            'error_message' => $exception->getMessage(),
        ]);

        $this->audioSample->processingRun->incrementFailed();
    }
}
