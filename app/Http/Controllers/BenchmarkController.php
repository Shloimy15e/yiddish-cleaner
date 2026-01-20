<?php

namespace App\Http\Controllers;

use App\Models\Transcription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class BenchmarkController extends Controller
{
    /**
     * Display the benchmark leaderboard.
     */
    public function index(Request $request): Response
    {
        $sortBy = $request->get('sort', 'wer');
        $sortDir = $request->get('dir', 'asc');

        $models = Transcription::query()
            ->select('model_name')
            ->selectRaw('COUNT(*) as sample_count')
            ->selectRaw('AVG(wer) as avg_wer')
            ->selectRaw('AVG(cer) as avg_cer')
            ->selectRaw('MIN(wer) as best_wer')
            ->selectRaw('MAX(wer) as worst_wer')
            ->selectRaw('AVG(substitutions) as avg_substitutions')
            ->selectRaw('AVG(insertions) as avg_insertions')
            ->selectRaw('AVG(deletions) as avg_deletions')
            ->whereNotNull('wer')
            ->groupBy('model_name')
            ->having('sample_count', '>=', 1)
            ->orderBy($sortBy === 'wer' ? 'avg_wer' : ($sortBy === 'cer' ? 'avg_cer' : 'sample_count'), $sortDir)
            ->get()
            ->map(function ($model, $index) {
                return [
                    'rank' => $index + 1,
                    'model_name' => $model->model_name,
                    'sample_count' => $model->sample_count,
                    'avg_wer' => round($model->avg_wer, 2),
                    'avg_cer' => round($model->avg_cer, 2),
                    'best_wer' => round($model->best_wer, 2),
                    'worst_wer' => round($model->worst_wer, 2),
                    'avg_substitutions' => round($model->avg_substitutions, 1),
                    'avg_insertions' => round($model->avg_insertions, 1),
                    'avg_deletions' => round($model->avg_deletions, 1),
                ];
            });

        $stats = [
            'total_transcriptions' => Transcription::count(),
            'total_models' => Transcription::distinct('model_name')->count('model_name'),
            'avg_wer' => round(Transcription::whereNotNull('wer')->avg('wer') ?? 0, 2),
            'avg_cer' => round(Transcription::whereNotNull('cer')->avg('cer') ?? 0, 2),
        ];

        return Inertia::render('Benchmark/Index', [
            'models' => $models,
            'stats' => $stats,
            'sort' => $sortBy,
            'dir' => $sortDir,
        ]);
    }

    /**
     * Display results for a specific model.
     */
    public function model(Request $request, string $modelName): Response
    {
        $modelName = urldecode($modelName);

        $transcriptions = Transcription::query()
            ->with(['audioSample:id,name,reference_text_clean'])
            ->where('model_name', $modelName)
            ->orderBy('wer', 'asc')
            ->paginate(25);

        $stats = Transcription::query()
            ->where('model_name', $modelName)
            ->selectRaw('COUNT(*) as sample_count')
            ->selectRaw('AVG(wer) as avg_wer')
            ->selectRaw('AVG(cer) as avg_cer')
            ->selectRaw('MIN(wer) as best_wer')
            ->selectRaw('MAX(wer) as worst_wer')
            ->selectRaw('STDDEV(wer) as stddev_wer')
            ->selectRaw('SUM(substitutions) as total_substitutions')
            ->selectRaw('SUM(insertions) as total_insertions')
            ->selectRaw('SUM(deletions) as total_deletions')
            ->selectRaw('SUM(reference_words) as total_words')
            ->first();

        $distribution = Transcription::query()
            ->where('model_name', $modelName)
            ->whereNotNull('wer')
            ->selectRaw('FLOOR(wer / 10) * 10 as bucket')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->pluck('count', 'bucket')
            ->toArray();

        return Inertia::render('Benchmark/Model', [
            'modelName' => $modelName,
            'transcriptions' => $transcriptions,
            'stats' => [
                'sample_count' => $stats->sample_count,
                'avg_wer' => round($stats->avg_wer ?? 0, 2),
                'avg_cer' => round($stats->avg_cer ?? 0, 2),
                'best_wer' => round($stats->best_wer ?? 0, 2),
                'worst_wer' => round($stats->worst_wer ?? 0, 2),
                'stddev_wer' => round($stats->stddev_wer ?? 0, 2),
                'total_substitutions' => $stats->total_substitutions ?? 0,
                'total_insertions' => $stats->total_insertions ?? 0,
                'total_deletions' => $stats->total_deletions ?? 0,
                'total_words' => $stats->total_words ?? 0,
            ],
            'distribution' => $distribution,
        ]);
    }

    /**
     * Compare multiple models side-by-side.
     */
    public function compare(Request $request): Response
    {
        $selectedModels = $request->get('models', []);

        if (is_string($selectedModels)) {
            $selectedModels = explode(',', $selectedModels);
        }

        $availableModels = Transcription::distinct('model_name')
            ->pluck('model_name')
            ->toArray();

        if (empty($selectedModels) && count($availableModels) >= 2) {
            $selectedModels = array_slice($availableModels, 0, 2);
        }

        $comparison = [];

        if (count($selectedModels) >= 2) {
            $sampleIds = DB::table('transcriptions')
                ->select('audio_sample_id')
                ->whereIn('model_name', $selectedModels)
                ->groupBy('audio_sample_id')
                ->havingRaw('COUNT(DISTINCT model_name) = ?', [count($selectedModels)])
                ->pluck('audio_sample_id');

            $transcriptions = Transcription::query()
                ->with('audioSample:id,name')
                ->whereIn('audio_sample_id', $sampleIds)
                ->whereIn('model_name', $selectedModels)
                ->get()
                ->groupBy('audio_sample_id');

            foreach ($transcriptions as $sampleId => $sampleTranscriptions) {
                $sample = $sampleTranscriptions->first()->audioSample;
                $modelResults = [];

                foreach ($selectedModels as $model) {
                    $t = $sampleTranscriptions->firstWhere('model_name', $model);
                    $modelResults[$model] = $t ? [
                        'wer' => $t->wer,
                        'cer' => $t->cer,
                        'hypothesis_text' => $t->hypothesis_text,
                    ] : null;
                }

                $comparison[] = [
                    'sample_id' => $sampleId,
                    'sample_name' => $sample->name ?? "Sample #{$sampleId}",
                    'models' => $modelResults,
                ];
            }
        }

        $modelStats = [];
        foreach ($selectedModels as $model) {
            $stats = Transcription::where('model_name', $model)
                ->selectRaw('AVG(wer) as avg_wer, AVG(cer) as avg_cer, COUNT(*) as count')
                ->first();

            $modelStats[$model] = [
                'avg_wer' => round($stats->avg_wer ?? 0, 2),
                'avg_cer' => round($stats->avg_cer ?? 0, 2),
                'count' => $stats->count,
            ];
        }

        return Inertia::render('Benchmark/Compare', [
            'availableModels' => $availableModels,
            'selectedModels' => $selectedModels,
            'comparison' => $comparison,
            'modelStats' => $modelStats,
        ]);
    }
}
