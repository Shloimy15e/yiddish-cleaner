<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessDocumentJob;
use App\Jobs\ProcessSheetBatchJob;
use App\Models\Document;
use App\Models\ProcessingRun;
use App\Services\Cleaning\CleanerService;
use App\Services\Document\ParserService;
use App\Services\Google\DriveService;
use App\Services\Google\SheetsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ProcessController extends Controller
{
    public function index(Request $request, CleanerService $cleaner): Response
    {
        $user = $request->user();

        // Get presets with full details
        $presets = collect(config('cleaning.presets'))->map(fn ($preset, $key) => [
            'name' => $preset['name'],
            'description' => $preset['description'],
            'processors' => $preset['processors'],
        ])->toArray();

        return Inertia::render('Process', [
            'presets' => $presets,
            'processors' => $cleaner->getProcessors(),
            'hasGoogleCredentials' => $user->hasGoogleCredential(),
            'recentRuns' => ProcessingRun::where('user_id', $user->id)
                ->latest()
                ->take(5)
                ->get(),
        ]);
    }

    public function uploadFile(Request $request, ParserService $parser): RedirectResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:doc,docx,txt|max:10240',
            'preset' => 'required|string',
            'mode' => 'required|in:rule,llm',
        ]);

        $user = $request->user();
        $file = $request->file('file');

        // Create run
        $run = ProcessingRun::create([
            'user_id' => $user->id,
            'batch_id' => Str::uuid(),
            'preset' => $request->preset,
            'mode' => $request->mode,
            'source_type' => 'upload',
            'total' => 1,
            'status' => 'pending',
        ]);

        // Save file
        $filePath = $parser->saveUploadedFile($file);

        // Create document
        $document = Document::create([
            'processing_run_id' => $run->id,
            'name' => $file->getClientOriginalName(),
            'status' => 'pending',
        ]);

        // Dispatch job
        ProcessDocumentJob::dispatch($document, $filePath);

        return redirect()->route('process.index')
            ->with('success', 'Document processing started.')
            ->with('runId', $run->id);
    }

    public function processDrive(Request $request, DriveService $drive, ParserService $parser): RedirectResponse
    {
        $request->validate([
            'url' => 'required|url',
            'preset' => 'required|string',
            'mode' => 'required|in:rule,llm',
        ]);

        $user = $request->user();
        $url = $request->url;

        $fileId = DriveService::extractFileId($url);
        if (!$fileId) {
            return back()->withErrors(['url' => 'Invalid Google Drive URL']);
        }

        // Create run
        $run = ProcessingRun::create([
            'user_id' => $user->id,
            'batch_id' => Str::uuid(),
            'preset' => $request->preset,
            'mode' => $request->mode,
            'source_type' => 'drive',
            'source_url' => $url,
            'total' => 1,
            'status' => 'pending',
        ]);

        try {
            $drive->forUser($user);
            $file = $drive->getFile($fileId);

            $tempPath = storage_path("app/temp/{$fileId}.docx");

            if (str_contains($file->getMimeType(), 'google-apps.document')) {
                $drive->exportAsDocx($fileId, $tempPath);
            } else {
                $drive->downloadFile($fileId, $tempPath);
            }

            $document = Document::create([
                'processing_run_id' => $run->id,
                'name' => $file->getName(),
                'source_url' => $url,
                'status' => 'pending',
            ]);

            ProcessDocumentJob::dispatch($document, $tempPath);

        } catch (\Exception $e) {
            $run->update(['status' => 'failed']);
            return back()->withErrors(['url' => 'Failed to download: ' . $e->getMessage()]);
        }

        return redirect()->route('process.index')
            ->with('success', 'Document processing started.')
            ->with('runId', $run->id);
    }

    public function processSheet(Request $request): RedirectResponse
    {
        $request->validate([
            'url' => 'required|url',
            'preset' => 'required|string',
            'mode' => 'required|in:rule,llm',
            'sheet_name' => 'nullable|string',
            'doc_link_column' => 'nullable|string',
            'processors' => 'nullable|array',
            'processors.*' => 'string',
            'row_limit' => 'nullable|integer|min:1|max:1000',
            'skip_completed' => 'nullable|boolean',
            'output_folder_url' => 'nullable|url',
        ]);

        $user = $request->user();
        $url = $request->url;

        $spreadsheetId = SheetsService::extractSpreadsheetId($url);
        if (!$spreadsheetId) {
            return back()->withErrors(['url' => 'Invalid Google Sheets URL']);
        }

        // Create run
        $run = ProcessingRun::create([
            'user_id' => $user->id,
            'batch_id' => Str::uuid(),
            'preset' => $request->preset,
            'mode' => $request->mode,
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
        );

        return redirect()->route('process.index')
            ->with('success', 'Batch processing started.')
            ->with('runId', $run->id);
    }

    public function showRun(ProcessingRun $run): Response
    {
        $this->authorize('view', $run);

        return Inertia::render('ProcessRun', [
            'run' => $run->load('documents'),
        ]);
    }
}
