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

    /**
     * Recalculate WER/CER metrics for a transcription.
     *
     * @param AudioSample $audioSample The audio sample with base transcription
     * @param Transcription $transcription The ASR transcription to calculate metrics for
     * @param int|null $refStart Start word index for reference (0-based, inclusive)
     * @param int|null $refEnd End word index for reference (0-based, inclusive)
     * @param int|null $hypStart Start word index for hypothesis (0-based, inclusive)
     * @param int|null $hypEnd End word index for hypothesis (0-based, inclusive)
     */
    public function handle(
        AudioSample $audioSample,
        Transcription $transcription,
        ?int $refStart = null,
        ?int $refEnd = null,
        ?int $hypStart = null,
        ?int $hypEnd = null,
    ): void {
        $referenceText = $audioSample->baseTranscription?->text_clean;

        $werResult = $this->werCalculator->calculate(
            $referenceText,
            $transcription->hypothesis_text,
            $refStart,
            $refEnd,
            $hypStart,
            $hypEnd,
        );

        $transcription->update([
            'wer' => $werResult->wer,
            'cer' => $werResult->cer,
            'substitutions' => $werResult->substitutions,
            'insertions' => $werResult->insertions,
            'deletions' => $werResult->deletions,
            'reference_words' => $werResult->referenceWords,
            'errors' => $werResult->errors,
            'wer_ref_start' => $werResult->refStart,
            'wer_ref_end' => $werResult->refEnd,
            'wer_hyp_start' => $werResult->hypStart,
            'wer_hyp_end' => $werResult->hypEnd,
        ]);
    }
}
