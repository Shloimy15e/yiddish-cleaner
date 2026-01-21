<?php

namespace App\Http\Controllers;

use App\Jobs\CalculateTranscriptionMetricsJob;
use App\Models\AudioSample;
use App\Models\Transcription;
use App\Services\Asr\WerCalculator;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TranscriptionController extends Controller
{
    /**
     * Display a single transcription.
     */
    public function show(AudioSample $audioSample, Transcription $transcription): Response
    {
        $transcription->load('audioSample');

        return Inertia::render('Transcriptions/Show', [
            'transcription' => $transcription,
            'audioSample' => $audioSample,
        ]);
    }

    /**
     * Store a manually entered transcription (benchmark).
     */
    public function store(Request $request, AudioSample $audioSample)
    {
        $validated = $request->validate([
            'provider' => 'required|string|max:255',
            'model' => 'required|string|max:255',
            'hypothesis_text' => 'required|string',
            'notes' => 'nullable|string|max:1000',
        ]);

        // Create model name from provider/model
        $modelName = $validated['provider'].'/'.$validated['model'];

        $transcription = Transcription::create([
            'audio_sample_id' => $audioSample->id,
            'model_name' => $modelName,
            'model_version' => $validated['model'],
            'source' => 'imported',
            'status' => Transcription::STATUS_PROCESSING,
            'hypothesis_text' => $validated['hypothesis_text'],
            'hypothesis_hash' => hash('sha256', $validated['hypothesis_text']),
            'wer' => null,
            'cer' => null,
            'substitutions' => 0,
            'insertions' => 0,
            'deletions' => 0,
            'reference_words' => 0,
            'errors' => [],
            'notes' => $validated['notes'],
        ]);

        // Save transcript as media file
        $tempPath = storage_path('app/temp/transcript_'.$transcription->id.'.txt');
        if (! is_dir(dirname($tempPath))) {
            mkdir(dirname($tempPath), 0755, true);
        }
        file_put_contents($tempPath, $validated['hypothesis_text']);
        $transcription->addMedia($tempPath)
            ->usingFileName('hypothesis.txt')
            ->toMediaCollection('hypothesis_transcript');

        CalculateTranscriptionMetricsJob::dispatch(
            audioSampleId: $audioSample->id,
            transcriptionId: $transcription->id,
        );

        return redirect()->route('audio-samples.show', $audioSample)
            ->with('success', 'Benchmark transcription added. Metrics are being calculated.');
    }

    /**
     * Import a transcription from a text file.
     */
    public function import(Request $request, AudioSample $audioSample)
    {
        $validated = $request->validate([
            'provider' => 'required|string|max:255',
            'model' => 'required|string|max:255',
            'file' => 'required|file|mimes:txt|max:10240',
            'notes' => 'nullable|string|max:1000',
        ]);

        $hypothesisText = file_get_contents($request->file('file')->getRealPath());

        $modelName = $validated['provider'].'/'.$validated['model'];

        $transcription = Transcription::create([
            'audio_sample_id' => $audioSample->id,
            'model_name' => $modelName,
            'model_version' => $validated['model'],
            'source' => 'imported',
            'status' => Transcription::STATUS_PROCESSING,
            'hypothesis_text' => $hypothesisText,
            'hypothesis_hash' => hash('sha256', $hypothesisText),
            'wer' => null,
            'cer' => null,
            'substitutions' => 0,
            'insertions' => 0,
            'deletions' => 0,
            'reference_words' => 0,
            'errors' => [],
            'notes' => $validated['notes'],
        ]);

        $transcription->addMediaFromRequest('file')
            ->usingFileName('hypothesis.txt')
            ->toMediaCollection('hypothesis_transcript');

        CalculateTranscriptionMetricsJob::dispatch(
            audioSampleId: $audioSample->id,
            transcriptionId: $transcription->id,
        );

        return redirect()->route('audio-samples.show', $audioSample)
            ->with('success', 'Transcription file imported. Metrics are being calculated.');
    }

    /**
     * Delete a transcription.
     */
    public function destroy(AudioSample $audioSample, Transcription $transcription)
    {
        if ($transcription->audio_sample_id !== $audioSample->id) {
            abort(404);
        }

        $transcription->delete();

        return redirect()->route('audio-samples.show', $audioSample)
            ->with('success', 'Transcription deleted successfully.');
    }

    /**
     * Recalculate WER/CER for a transcription.
     */
    public function recalculate(AudioSample $audioSample, Transcription $transcription, WerCalculator $werCalculator)
    {
        if ($transcription->audio_sample_id !== $audioSample->id) {
            abort(404);
        }

        $referenceText = $audioSample->reference_text_clean;

        if (! $referenceText || ! $transcription->hypothesis_text) {
            return redirect()->route('audio-samples.show', $audioSample)
                ->with('error', 'Cannot recalculate: missing reference or hypothesis text.');
        }

        $werResult = $werCalculator->calculate($referenceText, $transcription->hypothesis_text);

        $transcription->update([
            'wer' => $werResult->wer,
            'cer' => $werResult->cer,
            'substitutions' => $werResult->substitutions,
            'insertions' => $werResult->insertions,
            'deletions' => $werResult->deletions,
            'reference_words' => $werResult->referenceWords,
            'errors' => $werResult->errors,
        ]);

        return redirect()->route('audio-samples.show', $audioSample)
            ->with('success', 'WER/CER recalculated successfully.');
    }
}
