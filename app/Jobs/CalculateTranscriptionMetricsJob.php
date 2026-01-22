<?php

namespace App\Jobs;

use App\Models\AudioSample;
use App\Models\Transcription;
use App\Services\Asr\WerCalculator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class CalculateTranscriptionMetricsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct(
        public int $audioSampleId,
        public int $transcriptionId,
    ) {}

    public function handle(WerCalculator $werCalculator): void
    {
        $audioSample = AudioSample::find($this->audioSampleId);
        $transcription = Transcription::find($this->transcriptionId);

        if (! $audioSample || ! $transcription) {
            return;
        }

        if ($transcription->audio_sample_id !== $audioSample->id) {
            return;
        }

        $referenceText = $audioSample->baseTranscription?->text_clean;
        $hypothesisText = $transcription->hypothesis_text;

        if (! $referenceText || ! $hypothesisText) {
            $transcription->update([
                'wer' => null,
                'cer' => null,
                'substitutions' => 0,
                'insertions' => 0,
                'deletions' => 0,
                'reference_words' => 0,
                'errors' => [],
                'status' => Transcription::STATUS_COMPLETED,
            ]);

            return;
        }

        $werResult = $werCalculator->calculate($referenceText, $hypothesisText);

        $transcription->update([
            'wer' => $werResult->wer,
            'cer' => $werResult->cer,
            'substitutions' => $werResult->substitutions,
            'insertions' => $werResult->insertions,
            'deletions' => $werResult->deletions,
            'reference_words' => $werResult->referenceWords,
            'errors' => $werResult->errors,
            'status' => Transcription::STATUS_COMPLETED,
        ]);

        Log::info('Transcription metrics calculated', [
            'transcription_id' => $transcription->id,
            'audio_sample_id' => $audioSample->id,
            'wer' => $werResult->wer,
            'cer' => $werResult->cer,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('CalculateTranscriptionMetricsJob failed', [
            'transcription_id' => $this->transcriptionId,
            'audio_sample_id' => $this->audioSampleId,
            'error' => $exception->getMessage(),
        ]);

        Transcription::whereKey($this->transcriptionId)->update([
            'status' => Transcription::STATUS_FAILED,
        ]);
    }
}
