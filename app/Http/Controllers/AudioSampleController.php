<?php

namespace App\Http\Controllers;

use App\Jobs\TranscribeAudioSampleJob;
use App\Models\AudioSample;
use App\Models\AudioSampleStatusHistory;
use App\Models\ProcessingRun;
use App\Models\Transcription;
use App\Services\Document\ParserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class AudioSampleController extends Controller
{
    /**
     * List all audio samples for the current user.
     */
    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();

        $audioSamples = AudioSample::whereHas('processingRun', fn ($q) => $q->where('user_id', $user->id))
            ->select(['id', 'processing_run_id', 'name', 'status', 'created_at'])
            ->with([
                'processingRun:id,preset,mode,llm_provider,llm_model,batch_id',
                'baseTranscription:id,audio_sample_id,clean_rate',
            ])
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
     * Display an audio sample.
     */
    public function show(AudioSample $audioSample): InertiaResponse
    {
        $this->authorize('view', $audioSample);

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
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'audio_file' => 'nullable|file|mimes:mp3,wav,ogg,m4a,flac,mp4|max:102400',
            'transcript_file' => 'nullable|file|mimes:txt,docx,doc|max:10240',
            'transcript_text' => 'nullable|string|max:1000000',
        ]);

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
            'status' => AudioSample::STATUS_PENDING_BASE,
        ]);

        // Handle audio file upload
        if ($request->hasFile('audio_file')) {
            $audioSample->addMediaFromRequest('audio_file')
                ->toMediaCollection('audio');
        }

        // Handle transcript file - creates a base transcription
        if ($request->hasFile('transcript_file')) {
            $parser = app(ParserService::class);
            $transcriptFile = $request->file('transcript_file');
            $text = $parser->extractText(
                $transcriptFile->getRealPath(),
                $transcriptFile->getClientOriginalExtension()
            );

            if ($text) {
                $transcription = Transcription::create([
                    'type' => Transcription::TYPE_BASE,
                    'audio_sample_id' => $audioSample->id,
                    'name' => $request->name,
                    'source' => Transcription::SOURCE_IMPORTED,
                    'status' => Transcription::STATUS_COMPLETED,
                    'text_raw' => $text,
                    'hash_raw' => hash('sha256', $text),
                ]);

                // Save the transcript file to the transcription
                $transcription->addMediaFromRequest('transcript_file')
                    ->toMediaCollection('source_file');

                $audioSample->update(['status' => AudioSample::STATUS_UNCLEAN]);
            }
        }
        // Handle pasted transcript text - creates a base transcription
        elseif ($request->filled('transcript_text')) {
            $text = trim($request->input('transcript_text'));

            if ($text) {
                $transcription = Transcription::create([
                    'type' => Transcription::TYPE_BASE,
                    'audio_sample_id' => $audioSample->id,
                    'name' => $request->name,
                    'source' => Transcription::SOURCE_IMPORTED,
                    'status' => Transcription::STATUS_COMPLETED,
                    'text_raw' => $text,
                    'hash_raw' => hash('sha256', $text),
                ]);

                // Create a txt file from the pasted text
                $transcription->addMediaFromString($text)
                    ->usingFileName('transcript.txt')
                    ->toMediaCollection('source_file');

                $audioSample->update(['status' => AudioSample::STATUS_UNCLEAN]);
            }
        }

        $run->increment('total');
        $run->increment('completed');

        return redirect()->route('audio-samples.show', $audioSample)
            ->with('success', 'Audio sample created successfully.');
    }

    /**
     * Upload/replace transcript for an audio sample (creates/updates base transcription).
     */
    public function uploadTranscript(Request $request, AudioSample $audioSample, ParserService $parser): RedirectResponse
    {
        $this->authorize('update', $audioSample);

        $request->validate([
            'transcript' => 'required|file|mimes:txt,docx,doc,pdf|max:10240',
        ]);

        $transcriptFile = $request->file('transcript');
        $text = $parser->extractText(
            $transcriptFile->getRealPath(),
            $transcriptFile->getClientOriginalExtension()
        );

        if (! $text) {
            return back()->withErrors(['error' => 'Could not extract text from the uploaded file.']);
        }

        $existingBase = $audioSample->baseTranscription;

        if ($existingBase) {
            // Update existing base transcription, reset cleaning
            $existingBase->update([
                'text_raw' => $text,
                'hash_raw' => hash('sha256', $text),
                'text_clean' => null,
                'hash_clean' => null,
                'clean_rate' => null,
                'clean_rate_category' => null,
                'metrics' => null,
                'removals' => null,
                'validated_at' => null,
                'validated_by' => null,
                'status' => Transcription::STATUS_COMPLETED,
            ]);

            // Replace the transcript file
            $existingBase->clearMediaCollection('source_file');
            $existingBase->addMediaFromRequest('transcript')
                ->toMediaCollection('source_file');
        } else {
            // Create new base transcription
            $transcription = Transcription::create([
                'type' => Transcription::TYPE_BASE,
                'audio_sample_id' => $audioSample->id,
                'name' => $audioSample->name,
                'source' => Transcription::SOURCE_IMPORTED,
                'status' => Transcription::STATUS_COMPLETED,
                'text_raw' => $text,
                'hash_raw' => hash('sha256', $text),
            ]);

            // Save the transcript file
            $transcription->addMediaFromRequest('transcript')
                ->toMediaCollection('source_file');
        }

        $audioSample->update(['status' => AudioSample::STATUS_UNCLEAN]);

        return back()->with('success', 'Transcript uploaded successfully.');
    }

    /**
     * Upload/replace audio file for an audio sample.
     */
    public function uploadAudio(Request $request, AudioSample $audioSample): RedirectResponse
    {
        $this->authorize('update', $audioSample);

        $request->validate([
            'audio' => 'required|file|mimes:mp3,wav,ogg,m4a,flac,mp4|max:102400',
        ]);

        $filename = $request->file('audio')->getClientOriginalName();
        $filesize = $request->file('audio')->getSize();
        $hadAudio = $audioSample->hasMedia('audio');

        $audioSample->clearMediaCollection('audio');
        $audioSample->addMediaFromRequest('audio')->toMediaCollection('audio');

        AudioSampleStatusHistory::log(
            audioSample: $audioSample,
            action: $hadAudio ? 'audio_replaced' : 'audio_uploaded',
            fromStatus: $audioSample->status,
            toStatus: $audioSample->status,
            metadata: ['filename' => $filename, 'size' => $filesize],
        );

        return back()->with('success', 'Audio file uploaded successfully.');
    }

    /**
     * Delete an audio sample.
     */
    public function destroy(AudioSample $audioSample): RedirectResponse
    {
        $this->authorize('delete', $audioSample);

        $audioSample->delete();

        return redirect()->route('audio-samples.index')
            ->with('success', 'Audio sample deleted.');
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

        return back()->with('success', "Deleted {$count} sample(s).");
    }

    /**
     * Transcribe an audio sample using ASR.
     */
    public function transcribe(Request $request, AudioSample $audioSample): RedirectResponse
    {
        $this->authorize('update', $audioSample);

        $request->validate([
            'provider' => 'required|string|in:yiddishlabs,whisper',
            'model' => 'nullable|string',
            'notes' => 'nullable|string|max:1000',
        ]);

        if (! $audioSample->isReadyForBenchmark()) {
            return back()->withErrors(['error' => 'Sample must be validated before transcription.']);
        }

        $audioMedia = $audioSample->getFirstMedia('audio');
        if (! $audioMedia) {
            return back()->withErrors(['error' => 'No audio file attached to this sample.']);
        }

        $provider = $request->provider;
        $credential = $request->user()->getApiCredential($provider, 'asr');

        if (! $credential) {
            return back()->withErrors(['error' => "No API key configured for {$provider}. Add your API key in Settings."]);
        }

        $modelName = $request->model ? $provider.'/'.$request->model : $provider;
        $transcription = Transcription::create([
            'type' => Transcription::TYPE_ASR,
            'audio_sample_id' => $audioSample->id,
            'model_name' => $modelName,
            'model_version' => $request->model,
            'source' => Transcription::SOURCE_GENERATED,
            'status' => Transcription::STATUS_PENDING,
            'notes' => $request->notes,
        ]);

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
            ->where('status', AudioSample::STATUS_READY)
            ->get();

        $dispatched = 0;
        foreach ($samples as $sample) {
            if ($sample->getFirstMedia('audio')) {
                $modelName = $request->model ? $provider.'/'.$request->model : $provider;
                $transcription = Transcription::create([
                    'type' => Transcription::TYPE_ASR,
                    'audio_sample_id' => $sample->id,
                    'model_name' => $modelName,
                    'model_version' => $request->model,
                    'source' => Transcription::SOURCE_GENERATED,
                    'status' => Transcription::STATUS_PENDING,
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

    /**
     * Get audio samples without base transcription (API for linking modal).
     */
    public function linkableList(Request $request)
    {
        $user = $request->user();
        $search = $request->input('search');

        $audioSamples = AudioSample::whereHas('processingRun', fn ($q) => $q->where('user_id', $user->id))
            ->pendingBase()
            ->when($search, fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($sample) => [
                'id' => $sample->id,
                'name' => $sample->name,
                'status' => $sample->status,
                'has_audio' => $sample->hasAudio(),
                'created_at' => $sample->created_at,
            ]);

        return response()->json(['audioSamples' => $audioSamples]);
    }
}
