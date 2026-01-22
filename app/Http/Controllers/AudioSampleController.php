<?php

namespace App\Http\Controllers;

use App\Jobs\CleanAudioSampleJob;
use App\Jobs\ProcessSheetBatchJob;
use App\Jobs\ProcessSpreadsheetFileJob;
use App\Jobs\TranscribeAudioSampleJob;
use App\Models\AudioSample;
use App\Models\AudioSampleStatusHistory;
use App\Models\ProcessingRun;
use App\Services\Cleaning\CleanerService;
use App\Services\Cleaning\CleaningResult;
use App\Services\Cleaning\CleanRateCalculator;
use App\Services\DocxWriterService;
use App\Services\Document\ParserService;
use App\Services\Google\GoogleAuthService;
use App\Services\Google\SheetsService;
use App\Services\Llm\LlmManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AudioSampleController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();

        $audioSamples = AudioSample::whereHas('processingRun', fn ($q) => $q->where('user_id', $user->id))
            ->with(['processingRun:id,preset,mode,llm_provider,llm_model,batch_id', 'baseTranscription'])
            ->when($request->search, fn ($q, $search) => $q->where('name', 'like', "%{$search}%"))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->validated === 'yes', fn ($q) => $q->whereHas('baseTranscription', fn ($q) => $q->whereNotNull('validated_at')))
            ->when($request->validated === 'no', fn ($q) => $q->whereHas('baseTranscription', fn ($q) => $q->whereNull('validated_at')))
            ->when($request->category, fn ($q, $category) => $q->whereHas('baseTranscription', fn ($q) => $q->where('clean_rate_category', $category)))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('AudioSamples/Index', [
            'audioSamples' => $audioSamples,
            'filters' => $request->only(['search', 'status', 'validated', 'category']),
            'statuses' => AudioSample::STATUSES,
        ]);
    }


    /**
     * Show the form for creating audio samples (import page).
     * Import only - cleaning is done from the sample detail page.
     */
    public function create(Request $request, GoogleAuthService $auth): InertiaResponse
    {
        $user = $request->user();

        return Inertia::render('AudioSamples/Create', [
            'hasGoogleCredentials' => $auth->hasValidCredentials($user),
        ]);
    }

    public function show(AudioSample $audioSample): InertiaResponse
    {
        $this->authorize('view', $audioSample);

        // Get audio media info
        $audioMedia = $audioSample->getFirstMedia('audio');
        $audioInfo = $audioMedia ? [
            'url' => $audioMedia->getUrl(),
            'name' => $audioMedia->file_name,
            'size' => $audioMedia->size,
            'mime_type' => $audioMedia->mime_type,
        ] : null;

        return Inertia::render('AudioSamples/Show', [
            'audioSample' => $audioSample->load('processingRun', 'baseTranscription', 'asrTranscriptions'),
            'audioMedia' => $audioInfo,
            'presets' => config('cleaning.presets'),
        ]);
    }

    /**
     * Create a new audio sample (with or without transcript).
     * Supports URL or file upload for both audio and transcript.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            // Audio: either URL or file (both optional)
            'audio_url' => 'nullable|url|max:2048',
            'audio_file' => 'nullable|file|mimes:mp3,wav,ogg,m4a,flac,mp4|max:102400', // 100MB (m4a may be detected as mp4)
            // Transcript: either URL or file (one required)
            'transcript_url' => 'nullable|url|max:2048',
            'transcript_file' => 'nullable|file|mimes:txt,docx,doc|max:10240', // 10MB
        ]);

        // Ensure at least one transcript source is provided
        if (!$request->transcript_url && !$request->hasFile('transcript_file')) {
            return back()->withErrors(['transcript_url' => 'A transcript URL or file is required.']);
        }

        $user = $request->user();

        // Create or get a default processing run for manual uploads
        $run = ProcessingRun::firstOrCreate([
            'user_id' => $user->id,
            'batch_id' => 'manual-uploads',
        ], [
            'preset' => 'titles_only',
            'mode' => 'rule',
            'source_type' => 'manual',
            'status' => 'completed',
            'total' => 0,
            'completed' => 0,
            'failed' => 0,
        ]);

        // Create the audio sample
        $audioSample = AudioSample::create([
            'processing_run_id' => $run->id,
            'name' => $request->name,
            'audio_url' => $request->audio_url,
            'doc_url' => $request->transcript_url,
            'status' => AudioSample::STATUS_PENDING_TRANSCRIPT,
        ]);

        // Handle audio file upload
        if ($request->hasFile('audio_file')) {
            $audioSample->addMediaFromRequest('audio_file')
                ->toMediaCollection('audio');
        }

        // Handle transcript
        if ($request->hasFile('transcript_file')) {
            // File upload
            $parser = app(ParserService::class);
            $transcriptFile = $request->file('transcript_file');
            $transcriptPath = $transcriptFile->getRealPath();
            $originalExtension = $transcriptFile->getClientOriginalExtension();

            $audioSample->addMediaFromRequest('transcript_file')
                ->toMediaCollection('reference_transcript');

            $text = $parser->extractText($transcriptPath, $originalExtension);

            if ($text) {
                $audioSample->update([
                    'reference_text_raw' => $text,
                    'reference_hash_raw' => hash('sha256', $text),
                    'status' => AudioSample::STATUS_IMPORTED,
                ]);
            }
        } elseif ($request->transcript_url) {
            // URL - we'll need to fetch and parse it
            // For now, mark as pending and let the user know
            // The transcript will be fetched when cleaning is triggered
            $audioSample->update([
                'status' => AudioSample::STATUS_IMPORTED,
            ]);
        }

        $run->increment('total');
        $run->increment('completed');

        return redirect()->route('audio-samples.show', $audioSample)
            ->with('success', 'Audio sample created successfully.');
    }

    /**
     * Clean an audio sample's transcript (async via job).
     */
    public function clean(
        Request $request,
        AudioSample $audioSample,
    ) {
        $this->authorize('update', $audioSample);

        $request->validate([
            'preset' => 'required|string',
            'mode' => 'required|in:rule,llm',
            'llm_provider' => 'required_if:mode,llm|nullable|string',
            'llm_model' => 'required_if:mode,llm|nullable|string',
        ]);

        if (! $audioSample->canBeCleaned()) {
            return back()->withErrors(['error' => 'This sample cannot be cleaned. It may be missing a raw transcript.']);
        }

        // Validate LLM credentials if using LLM mode
        if ($request->mode === 'llm') {
            $provider = $request->llm_provider ?? 'openrouter';
            $credential = $request->user()->getApiCredential($provider, 'llm');

            if (! $credential) {
                return back()->withErrors(['error' => "No API key configured for {$provider}"]);
            }
        }

        $previousStatus = $audioSample->status;

        // Set status to cleaning
        $audioSample->update(['status' => AudioSample::STATUS_CLEANING]);

        // Log status history
        AudioSampleStatusHistory::log(
            audioSample: $audioSample,
            action: AudioSampleStatusHistory::ACTION_CLEANING_STARTED,
            fromStatus: $previousStatus,
            toStatus: AudioSample::STATUS_CLEANING,
            metadata: [
                'preset' => $request->preset,
                'mode' => $request->mode,
                'llm_provider' => $request->llm_provider,
                'llm_model' => $request->llm_model,
            ],
        );

        // Dispatch the cleaning job
        CleanAudioSampleJob::dispatch(
            audioSample: $audioSample,
            preset: $request->preset,
            mode: $request->mode,
            llmProvider: $request->llm_provider,
            llmModel: $request->llm_model,
            userId: $request->user()->id,
        );

        return back()->with('success', 'Cleaning started. The page will update when complete.');
    }

    /**
     * Bulk clean multiple audio samples.
     */
    public function bulkClean(Request $request): RedirectResponse
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'exists:audio_samples,id',
            'preset' => 'required|string',
            'mode' => 'required|in:rule,llm',
            'llm_provider' => 'required_if:mode,llm|nullable|string',
            'llm_model' => 'required_if:mode,llm|nullable|string',
        ]);

        $user = $request->user();

        // Validate LLM credentials if using LLM mode
        if ($request->mode === 'llm') {
            $provider = $request->llm_provider ?? 'openrouter';
            $credential = $user->getApiCredential($provider, 'llm');

            if (! $credential) {
                return back()->withErrors(['error' => "No API key configured for {$provider}"]);
            }
        }

        // Get samples that belong to this user and can be cleaned
        $samples = AudioSample::whereIn('id', $request->ids)
            ->whereHas('processingRun', fn ($q) => $q->where('user_id', $user->id))
            ->where('status', AudioSample::STATUS_IMPORTED)
            ->get();

        if ($samples->isEmpty()) {
            return back()->withErrors(['error' => 'No samples available for cleaning.']);
        }

        $count = 0;
        foreach ($samples as $sample) {
            $previousStatus = $sample->status;

            // Set status to cleaning
            $sample->update(['status' => AudioSample::STATUS_CLEANING]);

            // Log status history
            AudioSampleStatusHistory::log(
                audioSample: $sample,
                action: AudioSampleStatusHistory::ACTION_CLEANING_STARTED,
                fromStatus: $previousStatus,
                toStatus: AudioSample::STATUS_CLEANING,
                metadata: [
                    'preset' => $request->preset,
                    'mode' => $request->mode,
                    'llm_provider' => $request->llm_provider,
                    'llm_model' => $request->llm_model,
                    'bulk' => true,
                ],
            );

            // Dispatch the cleaning job
            CleanAudioSampleJob::dispatch(
                audioSample: $sample,
                preset: $request->preset,
                mode: $request->mode,
                llmProvider: $request->llm_provider,
                llmModel: $request->llm_model,
                userId: $user->id,
            );

            $count++;
        }

        return back()->with('success', "Started cleaning {$count} sample(s). The list will update as they complete.");
    }

    /**
     * Bulk delete audio samples.
     */
    public function bulkDelete(Request $request): RedirectResponse
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'exists:audio_samples,id',
        ]);

        $user = $request->user();

        $samples = AudioSample::whereIn('id', $request->ids)
            ->whereHas('processingRun', fn ($q) => $q->where('user_id', $user->id))
            ->get();

        if ($samples->isEmpty()) {
            return back()->withErrors(['error' => 'No samples available for deletion.']);
        }

        $count = 0;
        foreach ($samples as $sample) {
            $this->authorize('delete', $sample);
            $sample->delete();
            $count++;
        }

        return back()->with('success', "Deleted {$count} sample(s)." );
    }

    /**
     * Update an audio sample's cleaned text (inline edit).
     */
    public function update(Request $request, AudioSample $audioSample)
    {
        $this->authorize('update', $audioSample);

        $request->validate([
            'reference_text_clean' => 'required|string',
        ]);

        // Clear and re-save the cleaned transcript media
        $audioSample->clearMediaCollection('cleaned_transcript');

        $cleanedFilePath = storage_path('app/temp/cleaned_'.$audioSample->id.'.txt');
        file_put_contents($cleanedFilePath, $request->reference_text_clean);
        $audioSample->addMedia($cleanedFilePath)
            ->usingFileName('cleaned_transcript.txt')
            ->toMediaCollection('cleaned_transcript');

        $previousHash = $audioSample->reference_hash_clean;
        $newHash = hash('sha256', $request->reference_text_clean);

        // Update the cleaned text hash
        $audioSample->update([
            'reference_text_clean' => $request->reference_text_clean,
            'reference_hash_clean' => $newHash,
            // Reset validation since text was edited
            'validated_at' => null,
            'validated_by' => null,
        ]);

        // Log status history for the edit
        AudioSampleStatusHistory::log(
            audioSample: $audioSample,
            action: AudioSampleStatusHistory::ACTION_EDITED,
            fromStatus: $audioSample->status,
            toStatus: $audioSample->status,
            metadata: [
                'previous_hash' => $previousHash,
                'new_hash' => $newHash,
                'word_count' => str_word_count($request->reference_text_clean),
            ],
        );

        return back()->with('success', 'Cleaned text updated.');
    }

    /**
     * Upload/replace transcript for an audio sample.
     */
    public function uploadTranscript(Request $request, AudioSample $audioSample, ParserService $parser)
    {
        $this->authorize('update', $audioSample);

        $request->validate([
            'transcript' => 'required|file|mimes:txt,docx,doc,pdf|max:10240',
        ]);

        // Extract text BEFORE adding to media collection (temp file gets deleted after)
        $transcriptFile = $request->file('transcript');
        $transcriptPath = $transcriptFile->getRealPath();
        $originalExtension = $transcriptFile->getClientOriginalExtension();
        $originalName = $transcriptFile->getClientOriginalName();
        $text = $parser->extractText($transcriptPath, $originalExtension);

        if (! $text) {
            return back()->withErrors(['error' => 'Could not extract text from the uploaded file.']);
        }

        // Clear existing transcript media
        $audioSample->clearMediaCollection('reference_transcript');

        // Add new transcript file
        $audioSample->addMediaFromRequest('transcript')
            ->toMediaCollection('reference_transcript');

        $previousStatus = $audioSample->status;
        $hadTranscript = $audioSample->hasRawTranscript();

        // Reset cleaning data since we have a new source
        $audioSample->resetCleaning();

        $audioSample->update([
            'reference_text_raw' => $text,
            'reference_hash_raw' => hash('sha256', $text),
            'status' => AudioSample::STATUS_IMPORTED,
        ]);

        // Log status history
        AudioSampleStatusHistory::log(
            audioSample: $audioSample,
            action: $hadTranscript 
                ? AudioSampleStatusHistory::ACTION_TRANSCRIPT_REPLACED 
                : AudioSampleStatusHistory::ACTION_TRANSCRIPT_UPLOADED,
            fromStatus: $previousStatus,
            toStatus: AudioSample::STATUS_IMPORTED,
            metadata: [
                'filename' => $originalName,
                'word_count' => str_word_count($text),
            ],
        );

        return back()->with('success', 'Transcript uploaded successfully. You can now clean it.');
    }

    /**
     * Upload/replace audio file for an audio sample.
     */
    public function uploadAudio(Request $request, AudioSample $audioSample)
    {
        $this->authorize('update', $audioSample);

        $request->validate([
            'audio' => 'required|file|mimes:mp3,wav,ogg,m4a,flac,mp4|max:102400', // 100MB (m4a may be detected as mp4)
        ]);

        // Capture file info BEFORE moving (temp file gets deleted after)
        $filename = $request->file('audio')->getClientOriginalName();
        $filesize = $request->file('audio')->getSize();

        $hadAudio = $audioSample->hasMedia('audio');

        // Clear existing audio media
        $audioSample->clearMediaCollection('audio');

        // Add new audio file
        $audioSample->addMediaFromRequest('audio')
            ->toMediaCollection('audio');

        // Log status history
        AudioSampleStatusHistory::log(
            audioSample: $audioSample,
            action: $hadAudio ? 'audio_replaced' : 'audio_uploaded',
            fromStatus: $audioSample->status,
            toStatus: $audioSample->status,
            metadata: [
                'filename' => $filename,
                'size' => $filesize,
            ],
        );

        return back()->with('success', 'Audio file uploaded successfully.');
    }

    /**
     * Delete an audio sample.
     */
    public function destroy(AudioSample $audioSample)
    {
        $this->authorize('delete', $audioSample);

        $audioSample->delete();

        return redirect()->route('audio-samples.index')
            ->with('success', 'Audio sample deleted.');
    }

    public function validate(Request $request, AudioSample $audioSample)
    {
        $this->authorize('update', $audioSample);

        $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        if (! $audioSample->canBeValidated()) {
            return back()->withErrors(['error' => 'This sample cannot be validated. It must be cleaned first.']);
        }

        $audioSample->validate(
            validatedBy: $request->user()->name,
            notes: $request->notes,
        );

        return back()->with('success', 'Audio sample marked as Benchmark Ready.');
    }

    public function unvalidate(AudioSample $audioSample)
    {
        $this->authorize('update', $audioSample);

        $audioSample->unvalidate();

        return back()->with('success', 'Removed from Benchmark Ready.');
    }

    public function download(AudioSample $audioSample, DocxWriterService $writerService): StreamedResponse
    {
        $this->authorize('view', $audioSample);

        if (! $audioSample->reference_text_clean) {
            abort(404, 'No cleaned text available for this audio sample');
        }

        $baseName = pathinfo($audioSample->name, PATHINFO_FILENAME);
        $filename = $baseName.'_cleaned.docx';

        $content = $writerService->createDocument(
            $audioSample->reference_text_clean,
            $audioSample->metadata ?? null
        );

        return response()->streamDownload(
            function () use ($content) {
                echo $content;
            },
            $filename,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ]
        );
    }

    public function downloadOriginal(AudioSample $audioSample): StreamedResponse
    {
        $this->authorize('view', $audioSample);

        if (! $audioSample->reference_text_raw) {
            abort(404, 'No original text available for this audio sample');
        }

        $baseName = pathinfo($audioSample->name, PATHINFO_FILENAME);
        $filename = $baseName.'_original.txt';

        return response()->streamDownload(
            function () use ($audioSample) {
                echo $audioSample->reference_text_raw;
            },
            $filename,
            [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ]
        );
    }

    public function downloadText(AudioSample $audioSample): StreamedResponse
    {
        $this->authorize('view', $audioSample);

        if (! $audioSample->reference_text_clean) {
            abort(404, 'No cleaned text available for this audio sample');
        }

        $baseName = pathinfo($audioSample->name, PATHINFO_FILENAME);
        $filename = $baseName.'_cleaned.txt';

        return response()->streamDownload(
            function () use ($audioSample) {
                echo $audioSample->reference_text_clean;
            },
            $filename,
            [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ]
        );
    }

    /**
     * Batch import audio samples from a Google Sheet or uploaded spreadsheet file.
     */
    public function importSheet(Request $request): RedirectResponse
    {
        $request->validate([
            'url' => 'required_without:file|nullable|url',
            'file' => 'required_without:url|nullable|file|mimes:csv,xlsx,xls|max:10240',
            'sheet_name' => 'nullable|string',
            'doc_link_column' => 'required|string',
            'audio_url_column' => 'nullable|string',
            'row_limit' => 'nullable|integer|min:1|max:1000',
            'skip_completed' => 'nullable|boolean',
        ]);

        $docLinkColumn = trim((string) $request->input('doc_link_column', 'Doc Link'));
        $audioUrlColumn = trim((string) $request->input('audio_url_column', ''));
        $sheetName = trim((string) $request->input('sheet_name', ''));

        $user = $request->user();
        $isFileUpload = $request->hasFile('file');

        // Handle file upload
        if ($isFileUpload) {
            $file = $request->file('file');
            $filePath = $file->store('spreadsheet-imports', 'local');

            // Create run for file upload (import only, no cleaning)
            $run = ProcessingRun::create([
                'user_id' => $user->id,
                'batch_id' => Str::uuid(),
                'preset' => 'import_only',
                'mode' => 'import',
                'source_type' => 'file',
                'source_url' => $file->getClientOriginalName(),
                'status' => 'pending',
                'options' => [
                    'row_limit' => $request->row_limit ?? 100,
                    'skip_completed' => $request->skip_completed ?? true,
                ],
            ]);

            // Dispatch file processing job
            ProcessSpreadsheetFileJob::dispatch(
                $run,
                $filePath,
                $docLinkColumn,
                $audioUrlColumn,
            );

            return redirect()->route('audio-samples.run', $run)
                ->with('success', 'File import started.');
        }

        // Handle Google Sheets URL
        $url = $request->url;

        $spreadsheetId = SheetsService::extractSpreadsheetId($url);
        if (! $spreadsheetId) {
            return back()->withErrors(['url' => 'Invalid Google Sheets URL']);
        }

        logger()->info('Spreadsheet info:', [
            'spreadsheet_id' => $spreadsheetId,
            'sheet_name' => $sheetName,
            'doc_link_column' => $docLinkColumn,
            'audio_url_column' => $audioUrlColumn,
        ]);

        // Create run (import only, no cleaning)
        $run = ProcessingRun::create([
            'user_id' => $user->id,
            'batch_id' => Str::uuid(),
            'preset' => 'import_only',
            'mode' => 'import',
            'source_type' => 'sheet',
            'source_url' => $url,
            'status' => 'pending',
            'options' => [
                'row_limit' => $request->row_limit ?? 100,
                'skip_completed' => $request->skip_completed ?? true,
            ],
        ]);

        // Dispatch batch job
        ProcessSheetBatchJob::dispatch(
            $run,
            $spreadsheetId,
            $sheetName,
            $docLinkColumn,
            $audioUrlColumn,
        );

        return redirect()->route('audio-samples.run', $run)
            ->with('success', 'Batch import started.');
    }


    /**
     * Transcribe an audio sample using ASR.
     */
    public function transcribe(Request $request, AudioSample $audioSample)
    {
        $this->authorize('update', $audioSample);

        $request->validate([
            'provider' => 'required|string|in:yiddishlabs,whisper',
            'model' => 'nullable|string',
            'notes' => 'nullable|string|max:1000',
        ]);

        if (! $audioSample->isValidated()) {
            return back()->withErrors(['error' => 'Sample must be validated before transcription.']);
        }

        $audioMedia = $audioSample->getFirstMedia('audio');
        if (! $audioMedia) {
            return back()->withErrors(['error' => 'No audio file attached to this sample.']);
        }

        // Validate ASR credentials
        $provider = $request->provider;
        $credential = $request->user()->getApiCredential($provider, 'asr');

        if (! $credential) {
            return back()->withErrors(['error' => "No API key configured for {$provider}. Add your API key in Settings."]);
        }

        $modelName = $request->model ? $provider.'/'.$request->model : $provider;
        $transcription = $audioSample->transcriptions()->create([
            'model_name' => $modelName,
            'model_version' => $request->model,
            'source' => 'generated',
            'status' => \App\Models\Transcription::STATUS_PENDING,
            'notes' => $request->notes,
        ]);

        // Dispatch the transcription job
        TranscribeAudioSampleJob::dispatch(
            audioSample: $audioSample,
            provider: $provider,
            model: $request->model,
            notes: $request->notes,
            userId: $request->user()->id,
            transcriptionId: $transcription->id,
        );

        return back()->with('success', 'Transcription started. Results will appear when complete.');
    }

    /**
     * Bulk transcribe multiple audio samples.
     */
    public function bulkTranscribe(Request $request): RedirectResponse
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'exists:audio_samples,id',
            'provider' => 'required|string|in:yiddishlabs,whisper',
            'model' => 'nullable|string',
        ]);

        $user = $request->user();
        $provider = $request->provider;
        $credential = $user->getApiCredential($provider, 'asr');

        if (! $credential) {
            return back()->withErrors(['error' => "No API key configured for {$provider}. Add your API key in Settings."]);
        }

        $samples = AudioSample::whereIn('id', $request->ids)
            ->whereHas('processingRun', fn ($q) => $q->where('user_id', $user->id))
            ->where('status', AudioSample::STATUS_VALIDATED)
            ->get();

        $dispatched = 0;
        foreach ($samples as $sample) {
            if ($sample->getFirstMedia('audio')) {
                $modelName = $request->model ? $provider.'/'.$request->model : $provider;
                $transcription = $sample->transcriptions()->create([
                    'model_name' => $modelName,
                    'model_version' => $request->model,
                    'source' => 'generated',
                    'status' => \App\Models\Transcription::STATUS_PENDING,
                ]);
                TranscribeAudioSampleJob::dispatch(
                    audioSample: $sample,
                    provider: $provider,
                    model: $request->model,
                    userId: $user->id,
                    transcriptionId: $transcription->id,
                );
                $dispatched++;
            }
        }

        return back()->with('success', "{$dispatched} transcription job(s) dispatched.");
    }
}
