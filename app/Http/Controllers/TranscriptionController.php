<?php

namespace App\Http\Controllers;

use App\Jobs\AlignTranscriptionJob;
use App\Jobs\CalculateTranscriptionMetricsJob;
use App\Jobs\CleanTranscriptionJob;
use App\Models\AudioSample;
use App\Models\Transcription;
use App\Models\TranscriptionSegment;
use App\Models\TranscriptionWord;
use App\Services\Alignment\AlignmentManager;
use App\Services\Asr\WerCalculator;
use App\Services\Document\ParserService;
use App\Services\Llm\LlmManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;

class TranscriptionController extends Controller
{
    /**
     * Get LLM providers with their models for the current user.
     *
     * @return array<string, array{name: string, default_model: string, has_credential: bool, models: array}>
     */
    private function getLlmProviders(Request $request): array
    {
        $user = $request->user();
        $providers = config('cleaning.llm_providers', []);
        $llmController = app(\App\Http\Controllers\Api\LlmController::class);

        $result = [];
        foreach ($providers as $name => $config) {
            $hasCredential = \App\Models\ApiCredential::where('user_id', $user->id)
                ->where('provider', $name)
                ->where('type', 'llm')
                ->exists();

            $result[$name] = [
                'name' => ucfirst($name),
                'default_model' => $config['default_model'],
                'has_credential' => $hasCredential,
                'models' => $hasCredential ? $llmController->getStaticModels($name) : [],
            ];
        }

        return $result;
    }

    // ==================== Base Transcription CRUD ====================

    /**
     * Display a listing of base transcriptions.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $transcriptions = Transcription::base()
            ->when(! $user->isAdmin(), fn ($q) => $q->where('user_id', $user->id))
            ->select([
                'id',
                'name',
                'audio_sample_id',
                'user_id',
                'status',
                'clean_rate',
                'validated_at',
                'created_at',
            ])
            ->with(['audioSample:id,name', 'user:id,name'])
            ->when($request->search, fn ($q, $search) => $q->where('name', 'like', "%{$search}%"))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->linked === 'linked', fn ($q) => $q->whereNotNull('audio_sample_id'))
            ->when($request->linked === 'orphan', fn ($q) => $q->whereNull('audio_sample_id'))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Transcriptions/Index', [
            'transcriptions' => $transcriptions,
            'filters' => $request->only(['search', 'status', 'linked']),
            'presets' => config('cleaning.presets'),
            'llmProviders' => Inertia::optional(fn () => $this->getLlmProviders($request)),
        ]);
    }

    /**
     * Show the form for creating a base transcription.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('Transcriptions/Create', [
            'presets' => config('cleaning.presets'),
        ]);
    }

    /**
     * Store a new base transcription.
     */
    public function storeBase(Request $request, ParserService $parser): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'source_type' => 'required|in:file,text,url',
            'file' => 'required_if:source_type,file|nullable|file|mimes:txt,docx,doc,pdf|max:10240',
            'text' => 'required_if:source_type,text|nullable|string',
            'url' => 'required_if:source_type,url|nullable|url',
            'audio_sample_id' => 'nullable|exists:audio_samples,id',
        ]);

        $text = null;
        $source = Transcription::SOURCE_IMPORTED;

        // Extract text based on source type
        if ($request->source_type === 'file' && $request->hasFile('file')) {
            $file = $request->file('file');
            $text = $parser->extractText($file->getRealPath(), $file->getClientOriginalExtension());
            $source = Transcription::SOURCE_IMPORTED;
        } elseif ($request->source_type === 'text') {
            $text = $request->text;
            $source = Transcription::SOURCE_MANUAL;
        } elseif ($request->source_type === 'url') {
            // TODO: Handle URL-based import (Google Docs)
            return back()->withErrors(['url' => 'URL import not yet implemented.']);
        }

        if (! $text) {
            return back()->withErrors(['file' => 'Could not extract text from the provided source.']);
        }

        $transcription = Transcription::create([
            'user_id' => $request->user()->id,
            'type' => Transcription::TYPE_BASE,
            'name' => $request->name,
            'audio_sample_id' => $request->audio_sample_id,
            'text_raw' => $text,
            'hash_raw' => hash('sha256', $text),
            'source' => $source,
            'status' => Transcription::STATUS_PENDING,
        ]);

        // Store the source file if uploaded
        if ($request->hasFile('file')) {
            $transcription->addMediaFromRequest('file')
                ->usingFileName('source.'.$request->file('file')->getClientOriginalExtension())
                ->toMediaCollection('source_file');
        }

        // Sync audio sample status if linked
        if ($transcription->audio_sample_id) {
            $transcription->audioSample->syncStatusFromBaseTranscription();
        }

        return redirect()->route('transcriptions.show', $transcription)
            ->with('success', 'Base transcription created successfully.');
    }

    /**
     * Display a single transcription (base or ASR).
     */
    public function show(Request $request, Transcription $transcription): Response
    {
        $transcription->load(['audioSample.baseTranscription', 'user:id,name', 'words']);

        // Get audio media info for playback
        $audioMedia = $transcription->audioSample?->getFirstMedia('audio');
        $audioInfo = $audioMedia ? [
            'url' => $audioMedia->getUrl(),
            'name' => $audioMedia->file_name,
            'size' => $audioMedia->size,
            'mime_type' => $audioMedia->mime_type,
        ] : null;

        // Load word and segment review data
        $wordReviewData = $this->getWordReviewData($transcription);
        $segmentReviewData = $this->getSegmentReviewData($transcription);

        $viewData = [
            'transcription' => $transcription,
            'audioSample' => $transcription->audioSample,
            'audioMedia' => $audioInfo,
            'wordReview' => $wordReviewData,
            'segmentReview' => $segmentReviewData,
        ];

        // Add cleaning presets and LLM providers for base transcriptions
        if ($transcription->isBase()) {
            $viewData['presets'] = config('cleaning.presets');
            $viewData['llmProviders'] = Inertia::defer(fn () => $this->getLlmProviders($request));
        }

        // Append computed Custom WER attributes for ASR transcriptions
        if ($transcription->isAsr()) {
            $transcription->append([
                'custom_wer', 'custom_wer_error_count',
                'custom_wer_insertion_count', 'custom_wer_deletion_count',
                'custom_wer_critical_replacement_count', 'custom_wer_replacement_count',
                'reviewed_word_count',
            ]);
        }

        return Inertia::render('Transcriptions/Show', $viewData);
    }

    /**
     * Show a transcription in the context of an audio sample (for ASR).
     */
    public function showForAudioSample(AudioSample $audioSample, Transcription $transcription): Response
    {
        if ($transcription->audio_sample_id !== $audioSample->id) {
            abort(404);
        }

        $transcription->load(['audioSample.baseTranscription', 'words']);
        $audioSample->load('baseTranscription');

        // Get audio media info for word review playback
        $audioMedia = $audioSample->getFirstMedia('audio');
        $audioInfo = $audioMedia ? [
            'url' => $audioMedia->getUrl(),
            'name' => $audioMedia->file_name,
            'size' => $audioMedia->size,
            'mime_type' => $audioMedia->mime_type,
        ] : null;

        // Load word and segment review data
        $wordReviewData = $this->getWordReviewData($transcription);
        $segmentReviewData = $this->getSegmentReviewData($transcription);

        // Append computed Custom WER attributes
        $transcription->append([
            'custom_wer', 'custom_wer_error_count',
            'custom_wer_insertion_count', 'custom_wer_deletion_count',
            'custom_wer_critical_replacement_count', 'custom_wer_replacement_count',
            'reviewed_word_count',
        ]);

        return Inertia::render('Transcriptions/Show', [
            'transcription' => $transcription,
            'audioSample' => $audioSample,
            'audioMedia' => $audioInfo,
            'presets' => $transcription->isBase() ? config('cleaning.presets') : null,
            'wordReview' => $wordReviewData,
            'segmentReview' => $segmentReviewData,
        ]);
    }

    /**
     * Get word review data for a transcription.
     */
    private function getWordReviewData(Transcription $transcription): array
    {
        $words = $transcription->words()
            ->orderBy('word_index')
            ->get()
            ->map(fn ($word) => [
                'id' => $word->id,
                'transcription_id' => $word->transcription_id,
                'word_index' => (float) $word->word_index,
                'word' => $word->word,
                'start_time' => (float) $word->start_time,
                'end_time' => (float) $word->end_time,
                'confidence' => $word->confidence !== null ? (float) $word->confidence : null,
                'corrected_word' => $word->corrected_word,
                'is_deleted' => (bool) $word->is_deleted,
                'is_inserted' => (bool) $word->is_inserted,
                'is_critical_error' => (bool) $word->is_critical_error,
                'corrected_by' => $word->corrected_by,
                'corrected_at' => $word->corrected_at?->toISOString(),
            ]);

        $totalWords = $words->where('is_inserted', false)->count();
        $correctionCount = $words->filter(fn ($w) => $w['corrected_word'] !== null || $w['is_deleted'] || $w['is_inserted'] || $w['is_critical_error']
        )->count();

        return [
            'words' => $words->values()->all(),
            'stats' => [
                'total_words' => $totalWords,
                'correction_count' => $correctionCount,
                'correction_rate' => $totalWords > 0 ? $correctionCount / $totalWords : 0,
                'deleted_count' => $words->where('is_deleted', true)->count(),
                'inserted_count' => $words->where('is_inserted', true)->count(),
                'critical_error_count' => $words->where('is_critical_error', true)->count(),
                'critical_replacement_count' => $words->filter(fn ($w) => $w['is_critical_error'] === true
                    && $w['is_deleted'] === false
                    && $w['is_inserted'] === false
                )->count(),
                'replacement_count' => $words->filter(fn ($w) => $w['corrected_word'] !== null
                    && $w['is_deleted'] === false
                    && $w['is_inserted'] === false
                )->count(),
                'low_confidence_count' => $words
                    ->filter(fn ($w) => $w['confidence'] !== null && $w['confidence'] <= 0.7)
                    ->count(),
            ],
            'config' => [
                'playback_padding_seconds' => config('asr.review.playback_padding_seconds', 2.0),
                'default_confidence_threshold' => config('asr.review.default_confidence_threshold', 0.7),
            ],
        ];
    }

    /**
     * Get segment review data for a transcription.
     */
    private function getSegmentReviewData(Transcription $transcription): array
    {
        $segments = $transcription->segments()
            ->orderBy('segment_index')
            ->get()
            ->map(fn ($segment) => [
                'id' => $segment->id,
                'transcription_id' => $segment->transcription_id,
                'segment_index' => $segment->segment_index,
                'text' => $segment->text,
                'corrected_text' => $segment->corrected_text,
                'start_time' => (float) $segment->start_time,
                'end_time' => (float) $segment->end_time,
                'confidence' => $segment->confidence !== null ? (float) $segment->confidence : null,
                'words_json' => $segment->words_json,
                'corrected_by' => $segment->corrected_by,
                'corrected_at' => $segment->corrected_at?->toISOString(),
            ]);

        $totalSegments = $segments->count();
        $correctionCount = $segments->filter(fn ($s) => $s['corrected_text'] !== null)->count();

        return [
            'segments' => $segments->values()->all(),
            'stats' => [
                'total_segments' => $totalSegments,
                'correction_count' => $correctionCount,
                'correction_rate' => $totalSegments > 0 ? $correctionCount / $totalSegments : 0,
                'low_confidence_count' => $segments
                    ->filter(fn ($s) => $s['confidence'] !== null && $s['confidence'] <= 0.7)
                    ->count(),
            ],
            'config' => [
                'playback_padding_seconds' => config('asr.review.playback_padding_seconds', 2.0),
                'default_confidence_threshold' => config('asr.review.default_confidence_threshold', 0.7),
            ],
        ];
    }

    /**
     * Update a base transcription (name and/or cleaned text).
     */
    public function update(Request $request, Transcription $transcription): RedirectResponse
    {
        if (! $transcription->isBase()) {
            abort(403, 'Only base transcriptions can be edited.');
        }

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'text_clean' => 'sometimes|string',
        ]);

        // Update name if provided
        if ($request->has('name')) {
            $transcription->update(['name' => $request->name]);
        }

        // Update text_clean if provided
        if ($request->has('text_clean')) {
            // Clear and re-save the cleaned file
            $transcription->clearMediaCollection('cleaned_file');

            $cleanedFilePath = storage_path('app/temp/cleaned_'.$transcription->id.'.txt');
            File::ensureDirectoryExists(dirname($cleanedFilePath));
            file_put_contents($cleanedFilePath, $request->text_clean);
            $transcription->addMedia($cleanedFilePath)
                ->usingFileName('cleaned.txt')
                ->toMediaCollection('cleaned_file');

            $previousHash = $transcription->hash_clean;
            $newHash = hash('sha256', $request->text_clean);

            $transcription->update([
                'text_clean' => $request->text_clean,
                'hash_clean' => $newHash,
                'status' => Transcription::STATUS_COMPLETED,
                // Reset validation if text changed
                'validated_at' => $previousHash !== $newHash ? null : $transcription->validated_at,
                'validated_by' => $previousHash !== $newHash ? null : $transcription->validated_by,
            ]);

            // Sync audio sample status and recalculate WER if text changed
            $asrRecalculateCount = 0;
            if ($transcription->isLinked()) {
                $transcription->audioSample->syncStatusFromBaseTranscription();

                if ($previousHash !== $newHash) {
                    $asrTranscriptions = $transcription->audioSample->asrTranscriptions()
                        ->whereNotNull('hypothesis_text')
                        ->get();
                    $asrRecalculateCount = $asrTranscriptions->count();

                    foreach ($asrTranscriptions as $asrTranscription) {
                        CalculateTranscriptionMetricsJob::dispatchAfterResponse(
                            audioSampleId: $transcription->audio_sample_id,
                            transcriptionId: $asrTranscription->id,
                        );
                    }
                }
            }
        }

        $message = 'Transcription updated.';
        if (isset($asrRecalculateCount) && $asrRecalculateCount > 0) {
            $message .= " WER is being recalculated for {$asrRecalculateCount} ASR transcription(s).";
        }

        return back()->with('success', $message);
    }

    /**
     * Delete a transcription.
     */
    public function destroy(Transcription $transcription): RedirectResponse
    {
        $audioSample = $transcription->audioSample;
        $isBase = $transcription->isBase();

        $transcription->delete();

        // Sync audio sample status if it was a linked base transcription
        if ($isBase && $audioSample) {
            $audioSample->syncStatusFromBaseTranscription();
        }

        if ($audioSample) {
            return redirect()->route('audio-samples.show', $audioSample)
                ->with('success', 'Transcription deleted successfully.');
        }

        return redirect()->route('transcriptions.index')
            ->with('success', 'Transcription deleted successfully.');
    }

    // ==================== Cleaning ====================

    /**
     * Clean a base transcription.
     */
    public function clean(Request $request, Transcription $transcription): RedirectResponse
    {
        if (! $transcription->isBase()) {
            abort(403, 'Only base transcriptions can be cleaned.');
        }

        $request->validate([
            'preset' => 'required|string',
            'mode' => 'required|in:rule,llm',
            'llm_provider' => 'required_if:mode,llm|nullable|string',
            'llm_model' => 'required_if:mode,llm|nullable|string',
        ]);

        if (! $transcription->canBeCleaned()) {
            return back()->with('error', 'This transcription cannot be cleaned in its current state.');
        }

        // Validate LLM credentials if using LLM mode
        if ($request->mode === 'llm') {
            $llmManager = app(LlmManager::class);
            if (! $llmManager->hasCredentials($request->llm_provider)) {
                return back()->with('error', 'No credentials configured for the selected LLM provider.');
            }
        }

        $transcription->update([
            'status' => Transcription::STATUS_PROCESSING,
            'cleaning_preset' => $request->preset,
            'cleaning_mode' => $request->mode,
        ]);

        // Dispatch cleaning job
        CleanTranscriptionJob::dispatch(
            transcriptionId: $transcription->id,
            preset: $request->preset,
            mode: $request->mode,
            llmProvider: $request->llm_provider,
            llmModel: $request->llm_model,
        );

        return back()->with('success', 'Cleaning started. The page will update when complete.');
    }

    /**
     * Bulk clean multiple base transcriptions.
     */
    public function bulkClean(Request $request): RedirectResponse
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:transcriptions,id',
            'preset' => 'required|string',
            'mode' => 'required|in:rule,llm',
            'llm_provider' => 'required_if:mode,llm|nullable|string',
            'llm_model' => 'required_if:mode,llm|nullable|string',
            'include_already_cleaned' => 'boolean',
        ]);

        // Validate LLM credentials if using LLM mode
        if ($request->mode === 'llm') {
            $llmManager = app(LlmManager::class);
            if (! $llmManager->hasCredentials($request->llm_provider)) {
                return back()->with('error', 'No credentials configured for the selected LLM provider.');
            }
        }

        $includeAlreadyCleaned = $request->boolean('include_already_cleaned', false);
        $transcriptions = Transcription::base()
            ->whereIn('id', $request->ids)
            ->get();

        $queued = 0;
        $skipped = 0;

        foreach ($transcriptions as $transcription) {
            // Skip if already cleaned and not re-cleaning
            if ($transcription->isCleaned() && ! $includeAlreadyCleaned) {
                $skipped++;

                continue;
            }

            // Skip if cannot be cleaned (no raw text or already processing)
            if (! $transcription->canBeCleaned()) {
                $skipped++;

                continue;
            }

            $transcription->update([
                'status' => Transcription::STATUS_PROCESSING,
                'cleaning_preset' => $request->preset,
                'cleaning_mode' => $request->mode,
            ]);

            CleanTranscriptionJob::dispatch(
                transcriptionId: $transcription->id,
                preset: $request->preset,
                mode: $request->mode,
                llmProvider: $request->llm_provider,
                llmModel: $request->llm_model,
                userId: $request->user()->id,
            );

            $queued++;
        }

        $message = "Bulk cleaning started for {$queued} transcription(s).";
        if ($skipped > 0) {
            $message .= " {$skipped} skipped (already cleaned or cannot be cleaned).";
        }

        return back()->with('success', $message);
    }

    // ==================== Validation ====================

    /**
     * Validate a base transcription (mark as ready for benchmarking).
     */
    public function validate(Request $request, Transcription $transcription): RedirectResponse
    {
        if (! $transcription->isBase()) {
            abort(403, 'Only base transcriptions can be validated.');
        }

        if (! $transcription->canBeValidated()) {
            return back()->with('error', 'This transcription cannot be validated. It must be cleaned first.');
        }

        $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        $transcription->markValidated(
            validatedBy: $request->user()?->name,
            notes: $request->notes,
        );

        return back()->with('success', 'Transcription validated and ready for benchmarking.');
    }

    /**
     * Remove validation from a base transcription.
     */
    public function unvalidate(Transcription $transcription): RedirectResponse
    {
        if (! $transcription->isBase()) {
            abort(403, 'Only base transcriptions can be unvalidated.');
        }

        $transcription->unvalidate();

        return back()->with('success', 'Validation removed.');
    }

    // ==================== Linking ====================

    /**
     * Link a base transcription to an audio sample.
     */
    public function linkToAudioSample(Request $request, Transcription $transcription): RedirectResponse
    {
        if (! $transcription->isBase()) {
            abort(403, 'Only base transcriptions can be linked to audio samples.');
        }

        $request->validate([
            'audio_sample_id' => 'required|exists:audio_samples,id',
        ]);

        $audioSample = AudioSample::findOrFail($request->audio_sample_id);

        // Check if audio sample already has a base transcription
        if ($audioSample->hasBaseTranscription()) {
            return back()->with('error', 'This audio sample already has a base transcription linked.');
        }

        $transcription->linkToAudioSample($audioSample);

        return back()->with('success', 'Transcription linked to audio sample.');
    }

    /**
     * Unlink a base transcription from its audio sample.
     */
    public function unlinkFromAudioSample(Transcription $transcription): RedirectResponse
    {
        if (! $transcription->isBase()) {
            abort(403, 'Only base transcriptions can be unlinked.');
        }

        if (! $transcription->isLinked()) {
            return back()->with('error', 'This transcription is not linked to any audio sample.');
        }

        $transcription->unlinkFromAudioSample();

        return back()->with('success', 'Transcription unlinked from audio sample.');
    }

    /**
     * Get orphan base transcriptions for linking (API endpoint).
     */
    public function orphanList(Request $request)
    {
        $search = $request->input('search');

        $transcriptions = Transcription::orphan()
            ->when($search, function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%");
            })
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'name', 'status', 'validated_at', 'created_at']);

        return response()->json($transcriptions);
    }

    // ==================== ASR Transcription Methods ====================

    /**
     * Store a manually entered ASR transcription (benchmark).
     */
    public function storeAsr(Request $request, AudioSample $audioSample): RedirectResponse
    {
        $validated = $request->validate([
            'provider' => 'required|string|max:255',
            'model' => 'required|string|max:255',
            'hypothesis_text' => 'required|string',
            'notes' => 'nullable|string|max:1000',
        ]);

        $modelName = $validated['provider'].'/'.$validated['model'];

        $transcription = Transcription::create([
            'user_id' => $request->user()->id,
            'type' => Transcription::TYPE_ASR,
            'audio_sample_id' => $audioSample->id,
            'model_name' => $modelName,
            'model_version' => $validated['model'],
            'source' => Transcription::SOURCE_IMPORTED,
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
        File::ensureDirectoryExists(dirname($tempPath));
        file_put_contents($tempPath, $validated['hypothesis_text']);
        $transcription->addMedia($tempPath)
            ->usingFileName('hypothesis.txt')
            ->toMediaCollection('hypothesis_transcript');

        CalculateTranscriptionMetricsJob::dispatch(
            audioSampleId: $audioSample->id,
            transcriptionId: $transcription->id,
        );

        // Update audio sample status to benchmarked
        $audioSample->syncStatusFromBaseTranscription();

        return redirect()->route('audio-samples.show', $audioSample)
            ->with('success', 'Benchmark transcription added. Metrics are being calculated.');
    }

    /**
     * Import an ASR transcription from a text file.
     */
    public function importAsr(Request $request, AudioSample $audioSample): RedirectResponse
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
            'user_id' => $request->user()->id,
            'type' => Transcription::TYPE_ASR,
            'audio_sample_id' => $audioSample->id,
            'model_name' => $modelName,
            'model_version' => $validated['model'],
            'source' => Transcription::SOURCE_IMPORTED,
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

        // Update audio sample status
        $audioSample->syncStatusFromBaseTranscription();

        return redirect()->route('audio-samples.show', $audioSample)
            ->with('success', 'Transcription file imported. Metrics are being calculated.');
    }

    /**
     * Delete an ASR transcription (context of audio sample).
     */
    public function destroyAsr(AudioSample $audioSample, Transcription $transcription): RedirectResponse
    {
        if ($transcription->audio_sample_id !== $audioSample->id) {
            abort(404);
        }

        $transcription->delete();

        // Update audio sample status
        $audioSample->syncStatusFromBaseTranscription();

        return redirect()->route('audio-samples.show', $audioSample)
            ->with('success', 'Transcription deleted successfully.');
    }

    /**
     * Recalculate WER/CER for an ASR transcription.
     */
    public function recalculate(Request $request, AudioSample $audioSample, Transcription $transcription, WerCalculator $werCalculator): RedirectResponse
    {
        if ($transcription->audio_sample_id !== $audioSample->id) {
            abort(404);
        }

        // Validate range parameters
        $validated = $request->validate([
            'ref_start' => 'nullable|integer|min:0',
            'ref_end' => 'nullable|integer|min:0',
            'hyp_start' => 'nullable|integer|min:0',
            'hyp_end' => 'nullable|integer|min:0',
        ]);

        // Get reference text from base transcription
        $baseTranscription = $audioSample->baseTranscription;
        $referenceText = $baseTranscription?->text_clean;

        if (! $referenceText || ! $transcription->hypothesis_text) {
            return back()->with('error', 'Cannot recalculate: missing reference or hypothesis text.');
        }

        $ignoredWords = config('asr.wer.ignored_insertion_words', []);
        $werResult = $werCalculator->calculate(
            $referenceText,
            $transcription->hypothesis_text,
            $validated['ref_start'] ?? null,
            $validated['ref_end'] ?? null,
            $validated['hyp_start'] ?? null,
            $validated['hyp_end'] ?? null,
            $ignoredWords,
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

        return back()->with('success', 'WER/CER recalculated successfully.');
    }

    // ==================== Legacy Methods (redirect to new) ====================

    /**
     * @deprecated Use storeAsr instead
     */
    public function store(Request $request, AudioSample $audioSample)
    {
        return $this->storeAsr($request, $audioSample);
    }

    /**
     * @deprecated Use importAsr instead
     */
    public function import(Request $request, AudioSample $audioSample)
    {
        return $this->importAsr($request, $audioSample);
    }

    // ==================== Word Review Operations ====================

    /**
     * Update a word (correction, deletion, or critical error flag).
     */
    public function updateWord(Request $request, Transcription $transcription, TranscriptionWord $word): RedirectResponse
    {
        if ($word->transcription_id !== $transcription->id) {
            abort(403, 'Word does not belong to this transcription');
        }

        $validated = $request->validate([
            'corrected_word' => 'nullable|string|max:500',
            'is_deleted' => 'nullable|boolean',
            'is_critical_error' => 'nullable|boolean',
        ]);

        $userId = $request->user()->id;

        // Handle critical error flag (check first as it's an explicit action)
        if (array_key_exists('is_critical_error', $validated) && $validated['is_critical_error'] !== null) {
            if ($validated['is_critical_error'] === true) {
                $word->markCriticalError($userId);
            } else {
                $word->clearCriticalError();
            }

            return back();
        }

        // Handle deletion
        if (array_key_exists('is_deleted', $validated) && $validated['is_deleted'] !== null) {
            if ($validated['is_deleted'] === true) {
                $word->markDeleted($userId);
            } else {
                // Restore deleted word
                $word->update([
                    'is_deleted' => false,
                    'corrected_word' => $validated['corrected_word'] ?? null,
                ]);
            }

            return back();
        }

        // Handle correction
        if (array_key_exists('corrected_word', $validated)) {
            $word->applyCorrection($validated['corrected_word'], $userId);
        }

        return back();
    }

    /**
     * Insert a new word after another word.
     */
    public function insertWord(Request $request, Transcription $transcription): RedirectResponse
    {
        $validated = $request->validate([
            'word' => 'required|string|max:500',
            'after_word_id' => 'required|integer|exists:transcription_words,id',
        ]);

        $afterWord = TranscriptionWord::findOrFail($validated['after_word_id']);

        if ($afterWord->transcription_id !== $transcription->id) {
            abort(403, 'Word does not belong to this transcription');
        }

        $nextWord = $transcription->words()
            ->where('word_index', '>', $afterWord->word_index)
            ->orderBy('word_index')
            ->first();

        TranscriptionWord::insertBetween(
            $transcription,
            $validated['word'],
            $afterWord,
            $nextWord,
            $request->user()->id
        );

        return back();
    }

    /**
     * Bulk update words (delete or mark as critical error).
     */
    public function bulkUpdateWords(Request $request, Transcription $transcription): RedirectResponse
    {
        $validated = $request->validate([
            'word_ids' => 'required|array|min:1',
            'word_ids.*' => 'integer|exists:transcription_words,id',
            'action' => 'required|string|in:delete,mark_critical_error,clear_critical_error',
        ]);

        $userId = $request->user()->id;
        $words = TranscriptionWord::whereIn('id', $validated['word_ids'])
            ->where('transcription_id', $transcription->id)
            ->get();

        $updated = 0;

        foreach ($words as $word) {
            switch ($validated['action']) {
                case 'delete':
                    if ($word->is_inserted) {
                        $word->delete();
                    } else {
                        $word->markDeleted($userId);
                    }
                    $updated++;
                    break;

                case 'mark_critical_error':
                    if (! $word->is_deleted && ! $word->is_inserted) {
                        $word->markCriticalError($userId);
                        $updated++;
                    }
                    break;

                case 'clear_critical_error':
                    if ($word->is_critical_error) {
                        $word->clearCriticalError();
                        $updated++;
                    }
                    break;
            }
        }

        return back();
    }

    /**
     * Delete an inserted word permanently.
     */
    public function destroyWord(Request $request, Transcription $transcription, TranscriptionWord $word): RedirectResponse
    {
        if ($word->transcription_id !== $transcription->id) {
            abort(403, 'Word does not belong to this transcription');
        }

        if (! $word->is_inserted) {
            return back()->withErrors(['error' => 'Original ASR words cannot be permanently deleted.']);
        }

        $word->delete();

        return back();
    }

    // ==================== Segment Review Operations ====================

    /**
     * Update a segment (correction).
     */
    public function updateSegment(Request $request, Transcription $transcription, TranscriptionSegment $segment): RedirectResponse
    {
        if ($segment->transcription_id !== $transcription->id) {
            abort(403, 'Segment does not belong to this transcription');
        }

        $validated = $request->validate([
            'corrected_text' => 'nullable|string|max:5000',
        ]);

        $userId = $request->user()->id;

        if ($validated['corrected_text'] === null || $validated['corrected_text'] === $segment->text) {
            $segment->clearCorrection();
        } else {
            $segment->applyCorrection($validated['corrected_text'], $userId);
        }

        return back();
    }

    /**
     * Toggle training flag on transcription.
     */
    public function toggleTrainingFlag(Transcription $transcription): RedirectResponse
    {
        $transcription->update([
            'flagged_for_training' => ! $transcription->flagged_for_training,
        ]);

        return back();
    }

    // ==================== Word Alignment ====================

    /**
     * Generate word alignment for a transcription using forced alignment.
     *
     * This aligns the transcription text to the audio, creating word-level
     * timing data (TranscriptionWord records) for review and correction.
     */
    public function align(Request $request, Transcription $transcription, AlignmentManager $alignmentManager): RedirectResponse
    {
        $request->validate([
            'provider' => 'sometimes|string|in:'.implode(',', $alignmentManager->getProviders()),
            'model' => 'nullable|string',
            'overwrite' => 'sometimes|boolean',
        ]);

        // Ensure transcription is linked to an audio sample
        if (! $transcription->audioSample) {
            return back()->withErrors(['error' => 'Transcription must be linked to an audio sample for alignment.']);
        }

        // Check if audio file exists
        $audioMedia = $transcription->audioSample->getFirstMedia('audio');
        if (! $audioMedia) {
            return back()->withErrors(['error' => 'No audio file attached to the audio sample.']);
        }

        // Get text to align
        $text = $transcription->isBase()
            ? ($transcription->text_clean ?? $transcription->text_raw)
            : $transcription->hypothesis_text;

        if (empty($text)) {
            return back()->withErrors(['error' => 'No text available to align.']);
        }

        // Check if already has word data and overwrite not requested
        if (! $request->boolean('overwrite') && $transcription->hasWordData()) {
            return back()->withErrors(['error' => 'Transcription already has word alignment data. Enable "overwrite" to replace it.']);
        }

        $provider = $request->input('provider', $alignmentManager->getDefaultProvider());

        // Only require credentials if provider needs them
        $credential = null;
        if ($alignmentManager->requiresCredential($provider)) {
            $credential = $request->user()->getApiCredential($provider, 'alignment')
                ?? $request->user()->getApiCredential($provider, 'asr');

            if (! $credential) {
                return back()->withErrors(['error' => "No API key configured for {$provider}. Add your API key in Settings."]);
            }
        }

        // Dispatch the alignment job
        AlignTranscriptionJob::dispatch(
            transcription: $transcription,
            provider: $provider,
            model: $request->input('model'),
            userId: $request->user()->id,
            overwrite: $request->boolean('overwrite', false),
        );

        return back()->with('success', 'Word alignment started. Results will appear when complete.');
    }

    /**
     * Get available alignment providers and models (API endpoint).
     */
    public function alignmentProviders(Request $request, AlignmentManager $alignmentManager): \Illuminate\Http\JsonResponse
    {
        $providers = [];

        foreach ($alignmentManager->getProviders() as $providerName) {
            $config = $alignmentManager->getProviderConfig($providerName);
            $requiresCredential = $config['requires_credential'] ?? true;

            // Check for credential only if required
            $hasCredential = false;
            if ($requiresCredential) {
                $credential = $request->user()->getApiCredential($providerName, 'alignment')
                    ?? $request->user()->getApiCredential($providerName, 'asr');
                $hasCredential = $credential !== null;
            }

            $providers[] = [
                'id' => $providerName,
                'name' => $config['name'] ?? $providerName,
                'models' => $config['models'] ?? [],
                'default_model' => $config['default_model'] ?? null,
                'description' => $config['description'] ?? null,
                'requires_credential' => $requiresCredential,
                'has_credential' => $hasCredential,
                'available' => ! $requiresCredential || $hasCredential,
            ];
        }

        return response()->json([
            'providers' => $providers,
            'default' => $alignmentManager->getDefaultProvider(),
        ]);
    }
}
