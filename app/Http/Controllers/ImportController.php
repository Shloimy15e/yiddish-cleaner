<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessSheetBatchJob;
use App\Jobs\ProcessSpreadsheetFileJob;
use App\Models\ProcessingRun;
use App\Services\Google\GoogleAuthService;
use App\Services\Google\SheetsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class ImportController extends Controller
{
    /**
     * Show the import page for audio samples and transcripts.
     */
    public function create(Request $request, GoogleAuthService $auth): InertiaResponse
    {
        $user = $request->user();

        return Inertia::render('Import/Create', [
            'hasGoogleCredentials' => $auth->hasValidCredentials($user),
        ]);
    }

    /**
     * Batch import from a Google Sheet or uploaded spreadsheet file.
     * Supports importing audio samples, transcripts, or both linked together.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'url' => 'required_without:file|nullable|url',
            'file' => 'required_without:url|nullable|file|mimes:csv,xlsx,xls|max:10240',
            'sheet_name' => 'nullable|string',
            'doc_link_column' => 'required_without:audio_url_column|nullable|string',
            'audio_url_column' => 'required_without:doc_link_column|nullable|string',
            'row_limit' => 'nullable|integer|min:1|max:1000',
            'skip_completed' => 'nullable|boolean',
        ]);

        $docLinkColumn = trim((string) $request->input('doc_link_column', ''));
        $audioUrlColumn = trim((string) $request->input('audio_url_column', ''));
        $sheetName = trim((string) $request->input('sheet_name', ''));

        $user = $request->user();

        // Handle file upload
        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $filePath = $file->store('spreadsheet-imports', 'local');

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

            ProcessSpreadsheetFileJob::dispatch($run, $filePath, $docLinkColumn, $audioUrlColumn);

            return redirect()->route('imports.show', $run)
                ->with('success', 'File import started.');
        }

        // Handle Google Sheets URL
        $url = $request->url;
        $spreadsheetId = SheetsService::extractSpreadsheetId($url);

        if (! $spreadsheetId) {
            return back()->withErrors(['url' => 'Invalid Google Sheets URL']);
        }

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

        ProcessSheetBatchJob::dispatch($run, $spreadsheetId, $sheetName, $docLinkColumn, $audioUrlColumn);

        return redirect()->route('imports.show', $run)
            ->with('success', 'Batch import started.');
    }

    /**
     * List all import runs for the current user.
     */
    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();

        $runs = ProcessingRun::where('user_id', $user->id)
            ->where('mode', 'import')
            ->withCount(['audioSamples', 'audioSamples as completed_count' => fn ($q) => $q->whereNot('status', 'pending_base')])
            ->latest()
            ->paginate(20);

        return Inertia::render('Import/Index', [
            'runs' => $runs,
        ]);
    }

    /**
     * Show a specific import run with its results.
     */
    public function show(ProcessingRun $run): InertiaResponse
    {
        $this->authorize('view', $run);

        $run->load(['audioSamples' => fn ($q) => $q->with('baseTranscription')->latest()->limit(100)]);

        return Inertia::render('Import/Show', [
            'run' => $run,
        ]);
    }
}
