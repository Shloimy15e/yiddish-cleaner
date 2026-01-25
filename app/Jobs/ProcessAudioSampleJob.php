<?php

namespace App\Jobs;

use App\Events\AudioSampleProcessed;
use App\Models\AudioSample;
use App\Models\Transcription;
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
        $baseTranscription = null;

        try {
            $baseTranscription = $this->audioSample->baseTranscription;

            $text = $this->filePath
                ? $parser->extractText($this->filePath)
                : $baseTranscription?->text_raw;

            if (! $text) {
                throw new \RuntimeException('No text to process');
            }

            if (! $baseTranscription) {
                $baseTranscription = Transcription::create([
                    'user_id' => $this->audioSample->user_id,
                    'type' => Transcription::TYPE_BASE,
                    'audio_sample_id' => $this->audioSample->id,
                    'name' => $this->audioSample->name,
                    'source' => Transcription::SOURCE_IMPORTED,
                    'status' => Transcription::STATUS_PROCESSING,
                    'text_raw' => $text,
                    'hash_raw' => hash('sha256', $text),
                ]);
            } else {
                $baseTranscription->update([
                    'status' => Transcription::STATUS_PROCESSING,
                    'text_raw' => $text,
                    'hash_raw' => hash('sha256', $text),
                ]);
            }

            if ($this->filePath && file_exists($this->filePath)) {
                $baseTranscription->clearMediaCollection('source_file');
                $baseTranscription->addMedia($this->filePath)
                    ->preservingOriginal()
                    ->toMediaCollection('source_file');
            }

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

            $baseTranscription->update([
                'text_raw' => $result->originalText,
                'text_clean' => $result->cleanedText,
                'hash_raw' => $result->getOriginalHash(),
                'hash_clean' => $result->getCleanedHash(),
                'clean_rate' => $cleanRate->score,
                'clean_rate_category' => $cleanRate->category,
                'metrics' => $result->getMetrics(),
                'removals' => $result->removals,
                'cleaning_preset' => $run->preset,
                'cleaning_mode' => $run->mode,
                'status' => Transcription::STATUS_COMPLETED,
                'validated_at' => null,
                'validated_by' => null,
                'review_notes' => null,
            ]);

            // Save cleaned transcript as a text file
            $tempDir = storage_path('app/temp');
            if (! is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            $cleanedFilePath = $tempDir.'/cleaned_' . $this->audioSample->id . '.txt';
            file_put_contents($cleanedFilePath, $result->cleanedText);
            $baseTranscription->clearMediaCollection('cleaned_file');
            $baseTranscription->addMedia($cleanedFilePath)
                ->usingFileName('cleaned_transcript.txt')
                ->toMediaCollection('cleaned_file');

            $this->audioSample->syncStatusFromBaseTranscription();

            $run->incrementCompleted();

            if ($this->filePath) {
                $parser->cleanup($this->filePath);
            }

        } catch (Throwable $e) {
            if ($baseTranscription) {
                $baseTranscription->update([
                    'status' => Transcription::STATUS_FAILED,
                    'error_message' => $e->getMessage(),
                ]);
            }

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
