<?php

namespace App\Http\Controllers;

use App\Models\AudioSample;
use App\Models\TrainingVersion;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TrainingController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $versions = TrainingVersion::query()
            ->when(! $user->isAdmin(), fn ($q) => $q->where('user_id', $user->id))
            ->with('user:id,name')
            ->latest()
            ->paginate(10);

        return Inertia::render('Training/Index', [
            'versions' => $versions,
        ]);
    }

    public function create(Request $request): Response
    {
        $user = $request->user();

        // Get available audio samples for training
        $audioSamples = AudioSample::whereHas('processingRun', fn ($q) => $q->where('user_id', $user->id))
            ->where('status', 'completed')
            ->select('id', 'name', 'clean_rate', 'clean_rate_category', 'validated_at', 'metrics')
            ->get();

        return Inertia::render('Training/Create', [
            'availableAudioSamples' => $audioSamples,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'version' => 'required|string|max:50',
            'name' => 'required|string|max:200',
            'description' => 'nullable|string|max:1000',
            'criteria' => 'nullable|array',
            'audio_sample_ids' => 'required|array|min:1',
            'audio_sample_ids.*' => 'exists:audio_samples,id',
        ]);

        $user = $request->user();

        $version = TrainingVersion::create([
            'user_id' => $user->id,
            'version' => $request->version,
            'name' => $request->name,
            'description' => $request->description,
            'criteria' => $request->criteria,
        ]);

        // Attach audio samples
        $version->audioSamples()->attach($request->audio_sample_ids);
        $version->updateCounts();

        return redirect()->route('training.show', $version)
            ->with('success', 'Training version created.');
    }

    public function show(TrainingVersion $version): Response
    {
        $this->authorize('view', $version);

        return Inertia::render('Training/Show', [
            'version' => $version->load(['user:id,name', 'audioSamples']),
        ]);
    }

    public function activate(TrainingVersion $version)
    {
        $this->authorize('update', $version);

        $version->activate();

        return back()->with('success', 'Training version activated.');
    }

    public function export(Request $request, TrainingVersion $version)
    {
        $this->authorize('view', $version);

        $request->validate([
            'format' => 'required|in:json,csv,kaldi',
        ]);

        $audioSamples = $version->audioSamples()->with('baseTranscription')->get();

        $data = $audioSamples->map(fn ($sample) => [
            'id' => $sample->id,
            'name' => $sample->name,
            'text' => $sample->baseTranscription?->text_clean,
            'clean_rate' => $sample->clean_rate,
        ]);

        $filename = "training_{$version->version}_{$request->format}";

        if ($request->format === 'json') {
            return response()->json($data)
                ->header('Content-Disposition', "attachment; filename={$filename}.json");
        }

        if ($request->format === 'csv') {
            $csv = implode("\n", $data->map(fn ($d) => implode("\t", [$d['id'], $d['name'], str_replace(["\n", "\t"], ' ', $d['text'])])
            )->toArray());

            return response($csv, 200, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => "attachment; filename={$filename}.csv",
            ]);
        }

        // Kaldi format: utterance_id text
        $kaldi = $data->map(fn ($d) => "{$d['id']}\t{$d['text']}")->implode("\n");

        return response($kaldi, 200, [
            'Content-Type' => 'text/plain',
            'Content-Disposition' => "attachment; filename={$filename}.txt",
        ]);
    }
}
