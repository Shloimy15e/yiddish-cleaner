<?php

namespace App\Actions\Transcriptions;

use App\Models\AudioSample;
use App\Models\Transcription;
use App\Services\Asr\WerCalculator;

class RecalculateTranscriptionMetrics
{
    public function __construct(private WerCalculator $werCalculator)
    {
    }

    public function handle(AudioSample $audioSample, Transcription $transcription): void
    {
        $referenceText = $audioSample->baseTranscription?->text_clean;

        $werResult = $this->werCalculator->calculate(
            $referenceText,
            $transcription->hypothesis_text,
        );

        $transcription->update([
            'wer' => $werResult->wer,
            'cer' => $werResult->cer,
            'substitutions' => $werResult->substitutions,
            'insertions' => $werResult->insertions,
            'deletions' => $werResult->deletions,
            'reference_words' => $werResult->referenceWords,
            'errors' => $werResult->errors,
        ]);
    }
}
