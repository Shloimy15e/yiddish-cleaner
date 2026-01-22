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

        // Get stats with workflow-oriented metrics
        $stats = [
            'total_audio_samples' => $userSamplesQuery()->count(),
            'audio_samples_this_week' => $userSamplesQuery()
                ->where('created_at', '>=', now()->subWeek())->count(),
            'pending_base' => $userSamplesQuery()->pendingBase()->count(),
            'unclean' => $userSamplesQuery()->unclean()->count(),
            'ready' => $userSamplesQuery()->ready()->count(),
            'benchmarked' => $userSamplesQuery()->benchmarked()->count(),
        ];

        // Recent audio samples
        $recentAudioSamples = $userSamplesQuery()
            ->with('processingRun:id,preset,mode')
            ->latest()
            ->take(5)
            ->get(['id', 'name', 'processing_run_id', 'status', 'created_at']);

        // Active import runs
        $activeRuns = ProcessingRun::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'processing'])
            ->latest()
            ->take(5)
            ->get();

        // Pending Base queue (needs transcription)
        $pendingBaseQueue = $userSamplesQuery()
            ->pendingBase()
            ->latest()
            ->take(5)
            ->get(['id', 'name', 'status', 'created_at']);

        // Unclean queue (needs validation)
        $uncleanQueue = $userSamplesQuery()
            ->unclean()
            ->latest()
            ->take(5)
            ->get(['id', 'name', 'status', 'created_at']);

        // Ready queue (can run benchmarks)
        $readyQueue = $userSamplesQuery()
            ->ready()
            ->latest()
            ->take(5)
            ->get(['id', 'name', 'status', 'created_at']);

        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'recentAudioSamples' => $recentAudioSamples,
            'activeRuns' => $activeRuns,
            'pendingBaseQueue' => $pendingBaseQueue,
            'uncleanQueue' => $uncleanQueue,
            'readyQueue' => $readyQueue,
        ]);
    }
}
