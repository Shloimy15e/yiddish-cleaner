<?php

namespace App\Http\Controllers;

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
    public function store(Request $request, AudioSample $audioSample, WerCalculator $werCalculator)
    {
        $validated = $request->validate([
            'provider' => 'required|string|max:255',
            'model' => 'required|string|max:255',
            'hypothesis_text' => 'required|string',
            'notes' => 'nullable|string|max:1000',
        ]);

        // Calculate WER/CER against reference
        $referenceText = $audioSample->reference_text_clean;
        $werResult = null;

        if ($referenceText && $validated['hypothesis_text']) {
            $werResult = $werCalculator->calculate($referenceText, $validated['hypothesis_text']);
        }

        // Create model name from provider/model
        $modelName = $validated['provider'].'/'.$validated['model'];

        $transcription = Transcription::create([
            'audio_sample_id' => $audioSample->id,
            'model_name' => $modelName,
            'model_version' => $validated['model'],
            'source' => 'imported',
            'hypothesis_text' => $validated['hypothesis_text'],
            'hypothesis_hash' => hash('sha256', $validated['hypothesis_text']),
            'wer' => $werResult?->wer,
            'cer' => $werResult?->cer,
            'substitutions' => $werResult?->substitutions ?? 0,
            'insertions' => $werResult?->insertions ?? 0,
            'deletions' => $werResult?->deletions ?? 0,
            'reference_words' => $werResult?->referenceWords ?? 0,
            'errors' => $werResult?->errors ?? [],
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

        return redirect()->route('audio-samples.show', $audioSample)
            ->with('success', 'Benchmark transcription added successfully.');
    }

    /**
     * Import a transcription from a text file.
     */
    public function import(Request $request, AudioSample $audioSample, WerCalculator $werCalculator)
    {
        $validated = $request->validate([
            'provider' => 'required|string|max:255',
            'model' => 'required|string|max:255',
            'file' => 'required|file|mimes:txt|max:10240',
            'notes' => 'nullable|string|max:1000',
        ]);

        $hypothesisText = file_get_contents($request->file('file')->getRealPath());

        $referenceText = $audioSample->reference_text_clean;
        $werResult = null;

        if ($referenceText && $hypothesisText) {
            $werResult = $werCalculator->calculate($referenceText, $hypothesisText);
        }

        $modelName = $validated['provider'].'/'.$validated['model'];

        $transcription = Transcription::create([
            'audio_sample_id' => $audioSample->id,
            'model_name' => $modelName,
            'model_version' => $validated['model'],
            'source' => 'imported',
            'hypothesis_text' => $hypothesisText,
            'hypothesis_hash' => hash('sha256', $hypothesisText),
            'wer' => $werResult?->wer,
            'cer' => $werResult?->cer,
            'substitutions' => $werResult?->substitutions ?? 0,
            'insertions' => $werResult?->insertions ?? 0,
            'deletions' => $werResult?->deletions ?? 0,
            'reference_words' => $werResult?->referenceWords ?? 0,
            'errors' => $werResult?->errors ?? [],
            'notes' => $validated['notes'],
        ]);

        $transcription->addMediaFromRequest('file')
            ->usingFileName('hypothesis.txt')
            ->toMediaCollection('hypothesis_transcript');

        return redirect()->route('audio-samples.show', $audioSample)
            ->with('success', 'Transcription file imported successfully.');
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
