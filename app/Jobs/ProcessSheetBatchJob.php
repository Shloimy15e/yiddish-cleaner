<?php

namespace App\Jobs;

use App\Events\BatchCompleted;
use App\Models\AudioSample;
use App\Models\ProcessingRun;
use App\Services\Google\DriveService;
use App\Services\Google\SheetsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Import audio samples from a Google Sheet.
 *
 * This job ONLY imports data (downloads files, extracts text).
 * Cleaning is a separate action triggered from the AudioSample detail page.
 */
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
        public string $audioUrlColumn = '',
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
            $rowsToProcess = array_filter($rows, fn ($row) => ! empty($row[$this->docLinkColumn] ?? ''));

            $this->run->update(['total' => count($rowsToProcess)]);

            // Process each row
            $drive->forUser($user);

            foreach ($rowsToProcess as $row) {
                $docUrl = $row[$this->docLinkColumn];
                $rowIndex = $row['_row_index'];
                $audioUrl = ! empty($this->audioUrlColumn) ? ($row[$this->audioUrlColumn] ?? '') : '';

                // Create audio sample record with pending status
                $audioSample = AudioSample::create([
                    'processing_run_id' => $this->run->id,
                    'name' => $row['Name'] ?? "Row {$rowIndex}",
                    'source_url' => $docUrl,
                    'status' => AudioSample::STATUS_PENDING_TRANSCRIPT,
                ]);

                // Download and import (NO cleaning)
                try {
                    $fileId = DriveService::extractFileId($docUrl);
                    if (! $fileId) {
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

                    // Download audio file if URL is provided
                    if (! empty($audioUrl)) {
                        $this->downloadAndAttachAudio($audioSample, $audioUrl, $drive);
                    }

                    // Dispatch import job (no cleaning)
                    ImportAudioSampleJob::dispatch($audioSample, $tempPath);

                    // Update sheet status
                    $sheets->updateColumns($this->spreadsheetId, $this->sheetName, $rowIndex, [
                        'Status' => 'Imported',
                    ]);

                } catch (Throwable $e) {
                    $audioSample->update([
                        'status' => AudioSample::STATUS_FAILED,
                        'error_message' => $e->getMessage(),
                    ]);
                    $this->run->incrementFailed();

                    $sheets->updateColumns($this->spreadsheetId, $this->sheetName, $rowIndex, [
                        'Status' => 'Failed: '.$e->getMessage(),
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

        // Note: Completion is handled by individual ImportAudioSampleJob events
    }

    public function failed(Throwable $exception): void
    {
        $this->run->update([
            'status' => 'failed',
            'error_message' => $exception->getMessage(),
        ]);
        event(new BatchCompleted($this->run));
    }

    /**
     * Download audio file from URL and attach to AudioSample via Spatie Media Library.
     */
    protected function downloadAndAttachAudio(AudioSample $audioSample, string $audioUrl, DriveService $drive): void
    {
        // Check if it's a Google Drive URL
        $fileId = DriveService::extractFileId($audioUrl);

        if ($fileId) {
            // Download from Google Drive
            $file = $drive->getFile($fileId);
            $fileName = $file->getName();
            $tempPath = storage_path("app/temp/audio_{$fileId}");

            $drive->downloadFile($fileId, $tempPath);

            $audioSample->addMedia($tempPath)
                ->usingFileName($fileName)
                ->toMediaCollection('audio');
        } else {
            // Download from regular URL
            $audioSample->addMediaFromUrl($audioUrl)
                ->toMediaCollection('audio');
        }
    }
}
