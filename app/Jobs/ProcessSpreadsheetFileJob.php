<?php

namespace App\Jobs;

use App\Events\BatchCompleted;
use App\Models\AudioSample;
use App\Models\ProcessingRun;
use App\Services\Google\DriveService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * Import audio samples from an uploaded spreadsheet file (CSV/Excel).
 *
 * This job ONLY imports data (downloads files, extracts text).
 * Cleaning is a separate action triggered from the AudioSample detail page.
 */
class ProcessSpreadsheetFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(
        public ProcessingRun $run,
        public string $filePath,
        public string $docLinkColumn = 'Doc Link',
        public string $audioUrlColumn = '',
    ) {}

    public function handle(): void
    {
        $user = $this->run->user;

        try {
            $this->run->update(['status' => 'processing']);

            // Load spreadsheet
            $spreadsheet = IOFactory::load(Storage::disk('local')->path($this->filePath));
            $worksheet = $spreadsheet->getActiveSheet();

            // Get headers from first row
            $headers = [];
            $headerRow = $worksheet->getRowIterator(1, 1)->current();
            $cellIterator = $headerRow->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);

            $columnIndex = 0;
            foreach ($cellIterator as $cell) {
                $value = trim((string) $cell->getValue());
                if (! empty($value)) {
                    $headers[$columnIndex] = $value;
                }
                $columnIndex++;
            }

            // Find column indexes (case-insensitive, trimmed)
            $docLinkColumnIndex = $this->findHeaderIndex($headers, $this->docLinkColumn);
            $audioUrlColumnIndex = ! empty($this->audioUrlColumn)
                ? $this->findHeaderIndex($headers, $this->audioUrlColumn)
                : null;
            $nameColumnIndex = $this->findHeaderIndex($headers, 'Name');

            if ($docLinkColumnIndex === null) {
                throw new \RuntimeException("Column '{$this->docLinkColumn}' not found in spreadsheet. Available columns: ".implode(', ', $headers));
            }

            $docLinkHeader = $headers[$docLinkColumnIndex];
            $audioUrlHeader = $audioUrlColumnIndex !== null ? $headers[$audioUrlColumnIndex] : null;
            $nameHeader = $nameColumnIndex !== null ? $headers[$nameColumnIndex] : null;

            // Collect rows to process
            $rowsToProcess = [];
            $rowIterator = $worksheet->getRowIterator(2); // Start from row 2 (skip headers)

            foreach ($rowIterator as $row) {
                $cellIterator = $row->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(false);

                $rowData = [];
                $colIndex = 0;
                foreach ($cellIterator as $cell) {
                    if (isset($headers[$colIndex])) {
                        $rowData[$headers[$colIndex]] = trim((string) $cell->getValue());
                    }
                    $colIndex++;
                }

                // Only include rows with doc links
                if (! empty($rowData[$docLinkHeader] ?? '')) {
                    $rowData['_row_index'] = $row->getRowIndex();
                    $rowsToProcess[] = $rowData;
                }
            }

            $this->run->update(['total' => count($rowsToProcess)]);

            // Set up Drive service if needed for downloads
            $drive = app(DriveService::class);
            $drive->forUser($user);

            foreach ($rowsToProcess as $row) {
                $docUrl = $row[$docLinkHeader];
                $rowIndex = $row['_row_index'];
                $audioUrl = $audioUrlHeader ? ($row[$audioUrlHeader] ?? '') : '';
                $name = $nameHeader ? ($row[$nameHeader] ?? "Row {$rowIndex}") : "Row {$rowIndex}";

                try {
                    // Create audio sample record with pending status
                    $audioSample = AudioSample::create([
                        'processing_run_id' => $this->run->id,
                        'name' => $name,
                        'source_url' => $docUrl,
                        'status' => AudioSample::STATUS_PENDING_TRANSCRIPT,
                    ]);

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

                } catch (Throwable $e) {
                    if (isset($audioSample)) {
                        $audioSample->update([
                            'status' => AudioSample::STATUS_FAILED,
                            'error_message' => $e->getMessage(),
                        ]);
                    }
                    $this->run->incrementFailed();
                }
            }

            // Clean up uploaded file
            Storage::disk('local')->delete($this->filePath);

        } catch (Throwable $e) {
            $this->run->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            // Clean up uploaded file on failure too
            Storage::disk('local')->delete($this->filePath);

            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        $this->run->update([
            'status' => 'failed',
            'error_message' => $exception->getMessage(),
        ]);

        // Clean up uploaded file
        Storage::disk('local')->delete($this->filePath);

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

    private function findHeaderIndex(array $headers, string $columnName): ?int
    {
        $needle = trim($columnName);
        if ($needle === '') {
            return null;
        }

        foreach ($headers as $index => $header) {
            if (strcasecmp(trim((string) $header), $needle) === 0) {
                return $index;
            }
        }

        return null;
    }

}
