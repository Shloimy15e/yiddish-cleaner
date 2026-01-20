<?php

namespace App\Http\Controllers;

use App\Models\AudioSample;
use App\Models\ProcessingRun;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        // Base query for user's audio samples
        $userSamplesQuery = fn () => AudioSample::whereHas('processingRun', fn ($q) => $q->where('user_id', $user->id));

        // Get stats with new workflow-oriented metrics
        $stats = [
            'total_audio_samples' => $userSamplesQuery()->count(),
            'audio_samples_this_week' => $userSamplesQuery()
                ->where('created_at', '>=', now()->subWeek())->count(),
            'awaiting_cleaning' => $userSamplesQuery()->needsCleaning()->count(),
            'awaiting_review' => $userSamplesQuery()->pendingValidation()->count(),
            'benchmark_ready' => $userSamplesQuery()->benchmarkReady()->count(),
            'average_clean_rate' => $userSamplesQuery()
                ->whereNotNull('clean_rate')->avg('clean_rate') ?? 0,
        ];

        // Recent audio samples
        $recentAudioSamples = $userSamplesQuery()
            ->with('processingRun:id,preset,mode')
            ->latest()
            ->take(5)
            ->get(['id', 'name', 'processing_run_id', 'clean_rate', 'clean_rate_category', 'status', 'created_at']);

        // Active import runs
        $activeRuns = ProcessingRun::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'processing'])
            ->latest()
            ->take(5)
            ->get();

        // Needs Cleaning queue (imported but not yet cleaned)
        $needsCleaningQueue = $userSamplesQuery()
            ->needsCleaning()
            ->latest()
            ->take(5)
            ->get(['id', 'name', 'status', 'created_at']);

        // Needs Review queue (cleaned but not validated)
        $needsReviewQueue = $userSamplesQuery()
            ->pendingValidation()
            ->latest()
            ->take(5)
            ->get(['id', 'name', 'clean_rate', 'clean_rate_category', 'created_at']);

        // Benchmark Ready (validated samples)
        $benchmarkReadyQueue = $userSamplesQuery()
            ->benchmarkReady()
            ->latest()
            ->take(5)
            ->get(['id', 'name', 'clean_rate', 'clean_rate_category', 'created_at']);

        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'recentAudioSamples' => $recentAudioSamples,
            'activeRuns' => $activeRuns,
            'needsCleaningQueue' => $needsCleaningQueue,
            'needsReviewQueue' => $needsReviewQueue,
            'benchmarkReadyQueue' => $benchmarkReadyQueue,
        ]);
    }
}
