<?php

namespace App\Http\Controllers;

use App\Models\ProcessingRun;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class ProcessingRunController extends Controller
{
    /**
     * List import runs.
     */
    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();

        $runs = ProcessingRun::where('user_id', $user->id)
            ->where('mode', 'import')
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('ProcessRuns/Index', [
            'runs' => $runs,
        ]);
    }

    /**
     * Show a specific import/processing run.
     */
    public function show(ProcessingRun $run): InertiaResponse
    {
        $this->authorize('view', $run);

        return Inertia::render('ProcessRuns/Show', [
            'run' => $run->load(['audioSamples' => function ($query) {
                $query->with('baseTranscription:id,audio_sample_id,clean_rate')
                    ->latest()
                    ->select([
                        'id',
                        'processing_run_id',
                        'name',
                        'status',
                        'error_message',
                        'created_at',
                    ]);
            }]),
        ]);
    }
}
