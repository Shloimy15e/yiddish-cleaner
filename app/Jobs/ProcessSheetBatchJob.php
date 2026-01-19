<?php

namespace App\Jobs;

use App\Events\BatchCompleted;
use App\Models\Document;
use App\Models\ProcessingRun;
use App\Models\User;
use App\Services\Google\DriveService;
use App\Services\Google\SheetsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessSheetBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 3600;

    public function __construct(
        public ProcessingRun $run,
        public string $spreadsheetId,
        public string $sheetName = 'Sheet1',
        public string $docLinkColumn = 'Doc Link',
    ) {}

    public function handle(
        SheetsService $sheets,
        DriveService $drive,
    ): void {
        $user = $this->run->user;

        try {
            $this->run->update(['status' => 'processing']);

            // Get rows from sheet
            $sheets->forUser($user);
            $rows = $sheets->getRowsWithHeaders($this->spreadsheetId, $this->sheetName);

            // Filter rows with doc links
            $rowsToProcess = array_filter($rows, fn($row) => !empty($row[$this->docLinkColumn] ?? ''));

            $this->run->update(['total' => count($rowsToProcess)]);

            // Process each row
            $drive->forUser($user);

            foreach ($rowsToProcess as $row) {
                $docUrl = $row[$this->docLinkColumn];
                $rowIndex = $row['_row_index'];

                // Create document record
                $document = Document::create([
                    'processing_run_id' => $this->run->id,
                    'name' => $row['Name'] ?? "Row {$rowIndex}",
                    'source_url' => $docUrl,
                    'audio_link' => $row['Audio Link'] ?? null,
                    'status' => 'pending',
                ]);

                // Download and process
                try {
                    $fileId = DriveService::extractFileId($docUrl);
                    if (!$fileId) {
                        throw new \RuntimeException("Invalid Drive URL: {$docUrl}");
                    }

                    $tempPath = storage_path("app/temp/{$fileId}.docx");

                    // Download or export
                    $file = $drive->getFile($fileId);
                    if (str_contains($file->getMimeType(), 'google-apps.document')) {
                        $drive->exportAsDocx($fileId, $tempPath);
                    } else {
                        $drive->downloadFile($fileId, $tempPath);
                    }

                    // Dispatch processing job
                    ProcessDocumentJob::dispatch($document, $tempPath);

                    // Update sheet status
                    $sheets->updateColumns($this->spreadsheetId, $this->sheetName, $rowIndex, [
                        'Status' => 'Processing',
                    ]);

                } catch (Throwable $e) {
                    $document->update([
                        'status' => 'failed',
                        'error_message' => $e->getMessage(),
                    ]);
                    $this->run->incrementFailed();

                    $sheets->updateColumns($this->spreadsheetId, $this->sheetName, $rowIndex, [
                        'Status' => 'Failed: ' . $e->getMessage(),
                    ]);
                }
            }

        } catch (Throwable $e) {
            $this->run->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }

        // Note: Completion is handled by individual ProcessDocumentJob events
    }

    public function failed(Throwable $exception): void
    {
        $this->run->update([
            'status' => 'failed',
            'error_message' => $exception->getMessage(),
        ]);
        event(new BatchCompleted($this->run));
    }
}
