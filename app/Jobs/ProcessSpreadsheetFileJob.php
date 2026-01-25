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

            // Find column indexes (case-insensitive, trimmed) - both are optional
            $docLinkColumnIndex = ! empty($this->docLinkColumn)
                ? $this->findHeaderIndex($headers, $this->docLinkColumn)
                : null;
            $audioUrlColumnIndex = ! empty($this->audioUrlColumn)
                ? $this->findHeaderIndex($headers, $this->audioUrlColumn)
                : null;
            $nameColumnIndex = $this->findHeaderIndex($headers, 'Name');

            // Validate that at least one column was found
            if ($docLinkColumnIndex === null && $audioUrlColumnIndex === null) {
                $errorParts = [];
                if (! empty($this->docLinkColumn)) {
                    $errorParts[] = "Doc Link column '{$this->docLinkColumn}'";
                }
                if (! empty($this->audioUrlColumn)) {
                    $errorParts[] = "Audio URL column '{$this->audioUrlColumn}'";
                }
                throw new \RuntimeException('Column(s) not found in spreadsheet: ' . implode(', ', $errorParts) . '. Available columns: ' . implode(', ', $headers));
            }

            $docLinkHeader = $docLinkColumnIndex !== null ? $headers[$docLinkColumnIndex] : null;
            $audioUrlHeader = $audioUrlColumnIndex !== null ? $headers[$audioUrlColumnIndex] : null;
            $nameHeader = $nameColumnIndex !== null ? $headers[$nameColumnIndex] : null;

            // Collect rows to process
            $rowsToProcess = [];
            $rowLimit = (int) ($this->run->options['row_limit'] ?? 0);
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

                // Include rows that have either doc link OR audio URL
                $hasDocLink = $docLinkHeader && ! empty($rowData[$docLinkHeader] ?? '');
                $hasAudioUrl = $audioUrlHeader && ! empty($rowData[$audioUrlHeader] ?? '');
                
                if ($hasDocLink || $hasAudioUrl) {
                    $rowData['_row_index'] = $row->getRowIndex();
                    $rowsToProcess[] = $rowData;

                    if ($rowLimit > 0 && count($rowsToProcess) >= $rowLimit) {
                        break;
                    }
                }
            }

            $this->run->update(['total' => count($rowsToProcess)]);

            // Set up Drive service if needed for downloads
            $drive = app(DriveService::class);
            $drive->forUser($user);

            foreach ($rowsToProcess as $row) {
                $docUrl = $docLinkHeader ? ($row[$docLinkHeader] ?? '') : '';
                $rowIndex = $row['_row_index'];
                $audioUrl = $audioUrlHeader ? ($row[$audioUrlHeader] ?? '') : '';
                $name = "Row {$rowIndex}";

                try {
                    // Determine what data we have
                    $hasTranscript = ! empty($docUrl);
                    $hasAudio = ! empty($audioUrl);

                    // Create audio sample record
                    $audioSample = AudioSample::create([
                        'processing_run_id' => $this->run->id,
                        'name' => $name,
                        'source_url' => $docUrl ?: $audioUrl,
                        'status' => AudioSample::STATUS_PENDING_BASE,
                    ]);

                    $tempPath = null;

                    // Download transcript document if provided
                    $transcriptFileName = null;
                    if ($hasTranscript) {
                        $fileId = DriveService::extractFileId($docUrl);
                        if (! $fileId) {
                            throw new \RuntimeException("Invalid Drive URL: {$docUrl}");
                        }

                        $tempDir = storage_path('app/temp');
                        if (! is_dir($tempDir)) {
                            mkdir($tempDir, 0755, true);
                        }

                        // Download or export
                        $file = $drive->getFile($fileId);
                        $transcriptFileName = $file->getName();
                        $isGoogleDoc = str_contains((string) $file->getMimeType(), 'google-apps.document');
                        $extension = $this->resolveDocExtension($file->getName(), (string) $file->getMimeType(), $isGoogleDoc);
                        $tempPath = $tempDir."/{$fileId}.{$extension}";

                        if ($isGoogleDoc) {
                            $drive->exportAsDocx($fileId, $tempPath);
                        } else {
                            $drive->downloadFile($fileId, $tempPath);
                        }
                    }

                    // Download audio file if URL is provided
                    $audioFileName = null;
                    if ($hasAudio) {
                        $audioFileName = $this->downloadAndAttachAudio($audioSample, $audioUrl, $drive);
                    }

                    if ($audioFileName) {
                        $audioSample->update([
                            'name' => "Row {$rowIndex} - {$audioFileName}",
                        ]);
                    }

                    // Dispatch import job if we have a transcript, otherwise mark as ready
                    if ($tempPath) {
                        ImportAudioSampleJob::dispatch($audioSample, $tempPath, [
                            'transcription_file_name' => $transcriptFileName,
                        ]);
                    } else {
                        // Audio-only: no transcript to import, just increment completed
                        $this->run->incrementCompleted();
                    }

                } catch (Throwable $e) {
                    if (isset($audioSample)) {
                        $audioSample->update([
                            'status' => AudioSample::STATUS_DRAFT,
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
    protected function downloadAndAttachAudio(AudioSample $audioSample, string $audioUrl, DriveService $drive): ?string
    {
        // Check if it's a Google Drive URL
        $fileId = DriveService::extractFileId($audioUrl);

        if ($fileId) {
            // Download from Google Drive
            $file = $drive->getFile($fileId);
            $fileName = $file->getName();
            $tempDir = storage_path('app/temp');
            if (! is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            $tempPath = $tempDir."/audio_{$fileId}";

            $drive->downloadFile($fileId, $tempPath);

            $audioSample->addMedia($tempPath)
                ->usingFileName($fileName)
                ->toMediaCollection('audio');

            if (file_exists($tempPath)) {
                unlink($tempPath);
            }

            return $fileName;
        } else {
            // Download from regular URL
            $audioSample->addMediaFromUrl($audioUrl)
                ->toMediaCollection('audio');

            $path = (string) parse_url($audioUrl, PHP_URL_PATH);
            $base = $path !== '' ? basename($path) : '';

            return $base !== '' ? $base : null;
        }
    }

    private function resolveDocExtension(string $fileName, string $mimeType, bool $isGoogleDoc): string
    {
        if ($isGoogleDoc) {
            return 'docx';
        }

        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if ($extension !== '') {
            return $extension;
        }

        return $this->mapMimeToExtension($mimeType) ?? 'docx';
    }

    private function mapMimeToExtension(string $mimeType): ?string
    {
        return match (strtolower($mimeType)) {
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'text/plain' => 'txt',
            default => null,
        };
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
