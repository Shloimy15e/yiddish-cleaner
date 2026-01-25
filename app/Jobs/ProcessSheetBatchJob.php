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
        public string $sheetName = '',
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
            $sheets->ensureColumns($this->spreadsheetId, $this->sheetName, [
                'Processing Status',
                'Audio Sample ID',
                'Transcription ID',
                'Error Message',
            ]);
            $rows = $sheets->getRowsWithHeaders($this->spreadsheetId, $this->sheetName);

            // Resolve header names (case-insensitive, trimmed)
            $availableHeaders = array_keys($rows[0] ?? []);
            $availableHeaders = array_values(array_filter($availableHeaders, fn ($header) => $header !== '_row_index'));

            // Both columns are optional, but at least one should be provided
            $docLinkHeader = ! empty($this->docLinkColumn)
                ? $this->resolveHeader($availableHeaders, $this->docLinkColumn)
                : null;
            $audioUrlHeader = ! empty($this->audioUrlColumn)
                ? $this->resolveHeader($availableHeaders, $this->audioUrlColumn)
                : null;
            $nameHeader = $this->resolveHeader($availableHeaders, 'Name');

            // Validate that at least one column was found
            if (! $docLinkHeader && ! $audioUrlHeader) {
                $errorParts = [];
                if (! empty($this->docLinkColumn)) {
                    $errorParts[] = "Doc Link column '{$this->docLinkColumn}'";
                }
                if (! empty($this->audioUrlColumn)) {
                    $errorParts[] = "Audio URL column '{$this->audioUrlColumn}'";
                }
                throw new \RuntimeException('Column(s) not found in spreadsheet: ' . implode(', ', $errorParts) . '. Available columns: ' . implode(', ', $availableHeaders));
            }

            // Filter rows - include if either doc link OR audio URL is present
            $rowsToProcess = array_filter($rows, function ($row) use ($docLinkHeader, $audioUrlHeader) {
                $hasDocLink = $docLinkHeader && ! empty($row[$docLinkHeader] ?? '');
                $hasAudioUrl = $audioUrlHeader && ! empty($row[$audioUrlHeader] ?? '');
                return $hasDocLink || $hasAudioUrl;
            });

            $rowLimit = (int) ($this->run->options['row_limit'] ?? 0);
            if ($rowLimit > 0) {
                $rowsToProcess = array_slice($rowsToProcess, 0, $rowLimit);
            }

            $this->run->update(['total' => count($rowsToProcess)]);

            // Process each row
            $drive->forUser($user);

            foreach ($rowsToProcess as $row) {
                $docUrl = $docLinkHeader ? ($row[$docLinkHeader] ?? '') : '';
                $rowIndex = $row['_row_index'];
                $audioUrl = $audioUrlHeader ? ($row[$audioUrlHeader] ?? '') : '';

                try {
                    // Determine initial status based on what data we have
                    $hasTranscript = ! empty($docUrl);
                    $hasAudio = ! empty($audioUrl);
                    $initialStatus = $hasTranscript ? AudioSample::STATUS_PENDING_BASE : AudioSample::STATUS_PENDING_BASE;

                    // Create audio sample record
                    $audioSample = AudioSample::create([
                        'processing_run_id' => $this->run->id,
                        'name' => "Row {$rowIndex}",
                        'source_url' => $docUrl ?: $audioUrl,
                        'status' => $initialStatus,
                    ]);

                    // Update sheet with processing status and audio sample id
                    try {
                        $sheets->updateColumns($this->spreadsheetId, $this->sheetName, $rowIndex, [
                            'Processing Status' => 'Processing',
                            'Audio Sample ID' => (string) $audioSample->id,
                            'Transcription ID' => '',
                            'Error Message' => '',
                        ]);
                    } catch (Throwable) {
                        // Ignore status update failures to keep batch running
                    }

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
                            'spreadsheet_id' => $this->spreadsheetId,
                            'sheet_name' => $this->sheetName,
                            'row_index' => $rowIndex,
                            'transcription_file_name' => $transcriptFileName,
                        ]);
                    } else {
                        // Audio-only: no transcript to import, just increment completed
                        $this->run->incrementCompleted();

                        try {
                            $sheets->updateColumns($this->spreadsheetId, $this->sheetName, $rowIndex, [
                                'Processing Status' => 'Imported (audio only)',
                                'Audio Sample ID' => (string) $audioSample->id,
                                'Transcription ID' => '',
                                'Error Message' => '',
                            ]);
                        } catch (Throwable) {
                            // Ignore status update failures to keep batch running
                        }
                    }

                    // Update sheet status (best-effort)
                    try {
                        $sheets->updateColumns($this->spreadsheetId, $this->sheetName, $rowIndex, [
                            'Status' => 'Imported',
                        ]);
                    } catch (Throwable) {
                        // Ignore status update failures to keep batch running
                    }

                } catch (Throwable $e) {
                    if (isset($audioSample)) {
                        $audioSample->update([
                            'status' => AudioSample::STATUS_DRAFT,
                            'error_message' => $e->getMessage(),
                        ]);
                    }

                    $this->run->incrementFailed();

                    try {
                        $sheets->updateColumns($this->spreadsheetId, $this->sheetName, $rowIndex, [
                            'Status' => '',
                            'Processing Status' => 'Failed',
                            'Error Message' => $e->getMessage(),
                        ]);
                    } catch (Throwable) {
                        // Ignore status update failures to keep batch running
                    }
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

    private function resolveHeader(array $headers, string $columnName): ?string
    {
        $needle = trim($columnName);
        if ($needle === '') {
            return null;
        }

        foreach ($headers as $header) {
            if (strcasecmp(trim((string) $header), $needle) === 0) {
                return (string) $header;
            }
        }

        return null;
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

}
