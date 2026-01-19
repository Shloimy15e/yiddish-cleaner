<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\ProcessingRun;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        // Get stats
        $stats = [
            'total_documents' => Document::whereHas('processingRun', fn($q) => $q->where('user_id', $user->id))->count(),
            'documents_this_week' => Document::whereHas('processingRun', fn($q) => $q->where('user_id', $user->id))
                ->where('created_at', '>=', now()->subWeek())->count(),
            'pending_validation' => Document::whereHas('processingRun', fn($q) => $q->where('user_id', $user->id))
                ->pendingValidation()->count(),
            'average_clean_rate' => Document::whereHas('processingRun', fn($q) => $q->where('user_id', $user->id))
                ->whereNotNull('clean_rate')->avg('clean_rate') ?? 0,
        ];

        // Recent documents
        $recentDocuments = Document::whereHas('processingRun', fn($q) => $q->where('user_id', $user->id))
            ->with('processingRun:id,preset,mode')
            ->latest()
            ->take(10)
            ->get(['id', 'name', 'processing_run_id', 'clean_rate', 'clean_rate_category', 'status', 'created_at']);

        // Active runs
        $activeRuns = ProcessingRun::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'processing'])
            ->latest()
            ->take(5)
            ->get();

        // Validation queue
        $validationQueue = Document::whereHas('processingRun', fn($q) => $q->where('user_id', $user->id))
            ->pendingValidation()
            ->latest()
            ->take(10)
            ->get(['id', 'name', 'clean_rate', 'clean_rate_category', 'created_at']);

        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'recentDocuments' => $recentDocuments,
            'activeRuns' => $activeRuns,
            'validationQueue' => $validationQueue,
        ]);
    }
}
