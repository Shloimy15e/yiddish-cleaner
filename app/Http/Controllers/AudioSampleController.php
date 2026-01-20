<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessSheetBatchJob;
use App\Models\AudioSample;
use App\Models\ProcessingRun;
use App\Services\Cleaning\CleanerService;
use App\Services\Cleaning\CleaningResult;
use App\Services\Cleaning\CleanRateCalculator;
use App\Services\DocxWriterService;
use App\Services\Document\ParserService;
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
            ->with('processingRun:id,preset,mode,batch_id')
            ->when($request->search, fn ($q, $search) => $q->where('name', 'like', "%{$search}%"))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->validated === 'yes', fn ($q) => $q->whereNotNull('validated_at'))
            ->when($request->validated === 'no', fn ($q) => $q->whereNull('validated_at'))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('AudioSamples/Index', [
            'audioSamples' => $audioSamples,
            'filters' => $request->only(['search', 'status', 'validated']),
            'statuses' => AudioSample::STATUSES,
        ]);
    }

    /**
     * Show the form for creating audio samples (import page).
     */
    public function create(Request $request, CleanerService $cleaner): InertiaResponse
    {
        $user = $request->user();

        $presets = collect(config('cleaning.presets'))->map(fn ($preset, $key) => [
            'name' => $preset['name'],
            'description' => $preset['description'],
            'processors' => $preset['processors'],
        ])->toArray();

        return Inertia::render('AudioSamples/Create', [
            'presets' => $presets,
            'processors' => $cleaner->getProcessors(),
            'hasGoogleCredentials' => $user->hasGoogleCredential(),
            'recentRuns' => ProcessingRun::where('user_id', $user->id)
                ->latest()
                ->take(5)
                ->get(),
        ]);
    }

    public function show(AudioSample $audioSample): InertiaResponse
    {
        $this->authorize('view', $audioSample);

        return Inertia::render('AudioSamples/Show', [
            'audioSample' => $audioSample->load('processingRun', 'transcriptions'),
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
            'audio_file' => 'nullable|file|mimes:mp3,wav,ogg,m4a,flac|max:102400', // 100MB
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
            $transcriptPath = $request->file('transcript_file')->getRealPath();

            $audioSample->addMediaFromRequest('transcript_file')
                ->toMediaCollection('reference_transcript');

            $text = $parser->extractText($transcriptPath);

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
     * Clean an audio sample's transcript (sync for single docs).
     */
    public function clean(
        Request $request,
        AudioSample $audioSample,
        CleanerService $cleaner,
        CleanRateCalculator $calculator,
        LlmManager $llm,
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

        $text = $audioSample->reference_text_raw;

        if ($request->mode === 'llm') {
            $user = $request->user();
            $provider = $request->llm_provider ?? 'openrouter';
            $model = $request->llm_model ?? 'anthropic/claude-sonnet-4';
            $credential = $user->getApiCredential($provider, 'llm');

            if (! $credential) {
                return back()->withErrors(['error' => "No API key configured for {$provider}"]);
            }

            $cleanedText = $llm->clean($text, $provider, $credential, $model);

            $result = new CleaningResult(
                originalText: $text,
                cleanedText: $cleanedText,
                removals: [],
                processorResults: ['llm' => ['changes' => 1, 'removals' => []]],
            );
        } else {
            $result = $cleaner->cleanWithPreset($text, $request->preset);
        }

        $cleanRate = $calculator->calculate($result);

        // Clear any existing cleaned transcript media
        $audioSample->clearMediaCollection('cleaned_transcript');

        // Save cleaned transcript as a text file via media library
        $cleanedFilePath = storage_path('app/temp/cleaned_'.$audioSample->id.'.txt');
        file_put_contents($cleanedFilePath, $result->cleanedText);
        $audioSample->addMedia($cleanedFilePath)
            ->usingFileName('cleaned_transcript.txt')
            ->toMediaCollection('cleaned_transcript');

        // Update sample with cleaning results
        $audioSample->update([
            'reference_text_clean' => $result->cleanedText,
            'reference_hash_clean' => $result->getCleanedHash(),
            'clean_rate' => $cleanRate->score,
            'clean_rate_category' => $cleanRate->category,
            'metrics' => $result->getMetrics(),
            'removals' => $result->removals,
            'status' => AudioSample::STATUS_CLEANED,
            // Reset validation when cleaning/re-cleaning
            'validated_at' => null,
            'validated_by' => null,
            'review_notes' => null,
        ]);

        return back()->with('success', 'Transcript cleaned successfully.');
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

        // Update the cleaned text hash
        $audioSample->update([
            'reference_text_clean' => $request->reference_text_clean,
            'reference_hash_clean' => hash('sha256', $request->reference_text_clean),
            // Reset validation since text was edited
            'validated_at' => null,
            'validated_by' => null,
        ]);

        return back()->with('success', 'Cleaned text updated.');
    }

    /**
     * Upload/replace transcript for an audio sample.
     */
    public function uploadTranscript(Request $request, AudioSample $audioSample, ParserService $parser)
    {
        $this->authorize('update', $audioSample);

        $request->validate([
            'transcript' => 'required|file|mimes:txt,docx,pdf|max:10240',
        ]);

        // Clear existing transcript media
        $audioSample->clearMediaCollection('reference_transcript');

        // Add new transcript file
        $audioSample->addMediaFromRequest('transcript')
            ->toMediaCollection('reference_transcript');

        // Extract and save raw text
        $transcriptPath = $request->file('transcript')->getRealPath();
        $text = $parser->extractText($transcriptPath);

        if (! $text) {
            return back()->withErrors(['error' => 'Could not extract text from the uploaded file.']);
        }

        // Reset cleaning data since we have a new source
        $audioSample->resetCleaning();

        $audioSample->update([
            'reference_text_raw' => $text,
            'reference_hash_raw' => hash('sha256', $text),
            'status' => AudioSample::STATUS_IMPORTED,
        ]);

        return back()->with('success', 'Transcript uploaded successfully. You can now clean it.');
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
     * Batch import audio samples from a Google Sheet.
     */
    public function importSheet(Request $request): RedirectResponse
    {
        $request->validate([
            'url' => 'required|url',
            'preset' => 'required|string',
            'mode' => 'required|in:rule,llm',
            'llm_provider' => 'nullable|string',
            'llm_model' => 'nullable|string',
            'sheet_name' => 'nullable|string',
            'doc_link_column' => 'nullable|string',
            'audio_url_column' => 'nullable|string',
            'processors' => 'nullable|array',
            'processors.*' => 'string',
            'row_limit' => 'nullable|integer|min:1|max:1000',
            'skip_completed' => 'nullable|boolean',
            'output_folder_url' => 'nullable|url',
        ]);

        $user = $request->user();
        $url = $request->url;

        $spreadsheetId = SheetsService::extractSpreadsheetId($url);
        if (! $spreadsheetId) {
            return back()->withErrors(['url' => 'Invalid Google Sheets URL']);
        }

        // Create run
        $run = ProcessingRun::create([
            'user_id' => $user->id,
            'batch_id' => Str::uuid(),
            'preset' => $request->preset,
            'mode' => $request->mode,
            'llm_provider' => $request->llm_provider ?? 'openrouter',
            'llm_model' => $request->llm_model ?? 'anthropic/claude-sonnet-4',
            'source_type' => 'sheet',
            'source_url' => $url,
            'status' => 'pending',
            'options' => [
                'processors' => $request->processors,
                'row_limit' => $request->row_limit ?? 100,
                'skip_completed' => $request->skip_completed ?? true,
                'output_folder_url' => $request->output_folder_url,
            ],
        ]);

        // Dispatch batch job
        ProcessSheetBatchJob::dispatch(
            $run,
            $spreadsheetId,
            $request->sheet_name ?? 'Sheet1',
            $request->doc_link_column ?? 'Doc Link',
            $request->audio_url_column ?? '',
        );

        return redirect()->route('audio-samples.create')
            ->with('success', 'Batch import started.')
            ->with('runId', $run->id);
    }

    /**
     * Show a specific import/processing run.
     */
    public function showRun(ProcessingRun $run): InertiaResponse
    {
        $this->authorize('view', $run);

        return Inertia::render('ProcessRun', [
            'run' => $run->load('documents'),
        ]);
    }
}
