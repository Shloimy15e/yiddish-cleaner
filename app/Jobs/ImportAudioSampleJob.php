<?php

namespace App\Jobs;

use App\Events\AudioSampleProcessed;
use App\Models\AudioSample;
use App\Models\Transcription;
use App\Services\Document\ParserService;
use App\Services\Google\SheetsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Import an audio sample - downloads files and extracts text.
 *
 * This job ONLY imports data (no cleaning).
 * After import, a base transcription is created and linked to the audio sample.
 * The sample status becomes 'unclean' (has base transcription, needs cleaning).
 */
class ImportAudioSampleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        public AudioSample $audioSample,
        public ?string $filePath = null,
        public ?array $sheetContext = null,
    ) {}

    public function handle(ParserService $parser, SheetsService $sheets): void
    {
        $run = $this->audioSample->processingRun;

        try {
            // Save the reference transcript file to media collection if provided
            if ($this->filePath && file_exists($this->filePath)) {
                $this->audioSample->addMedia($this->filePath)
                    ->preservingOriginal() // Keep file for text extraction
                    ->toMediaCollection('reference_transcript');
            }

            // Extract text from the document
            $text = $this->filePath
                ? $parser->extractText($this->filePath)
                : null;

            if (! $text) {
                // No transcript text - mark as pending base transcription
                $this->audioSample->update([
                    'status' => AudioSample::STATUS_PENDING_BASE,
                ]);
                $run->incrementCompleted();

                $this->updateSheet($sheets, [
                    'Processing Status' => 'Pending Base',
                    'Audio Sample ID' => (string) $this->audioSample->id,
                    'Transcription ID' => '',
                    'Error Message' => '',
                ]);

                if ($this->filePath) {
                    $parser->cleanup($this->filePath);
                }

                event(new AudioSampleProcessed($run->fresh(), $this->audioSample->fresh()));

                return;
            }

            // Calculate hash for raw text
            $rawHash = hash('sha256', $text);

            // Create a base transcription linked to this audio sample
            $transcriptionFileName = $this->sheetContext['transcription_file_name'] ?? null;
            $rowIndex = $this->sheetContext['row_index'] ?? null;
            $transcriptionName = ($transcriptionFileName && $rowIndex)
                ? "Row {$rowIndex} - {$transcriptionFileName}"
                : $this->audioSample->name;

            $transcription = Transcription::create([
                'user_id' => $this->audioSample->user_id,
                'type' => Transcription::TYPE_BASE,
                'audio_sample_id' => $this->audioSample->id,
                'name' => $transcriptionName,
                'source' => Transcription::SOURCE_IMPORTED,
                'status' => Transcription::STATUS_COMPLETED,
                'text_raw' => $text,
                'hash_raw' => $rawHash,
            ]);

            // Update audio sample status (has base transcription now, needs cleaning)
            $this->audioSample->update([
                'status' => AudioSample::STATUS_UNCLEAN,
            ]);

            $run->incrementCompleted();

            $this->updateSheet($sheets, [
                'Processing Status' => 'Imported',
                'Audio Sample ID' => (string) $this->audioSample->id,
                'Transcription ID' => (string) $transcription->id,
                'Error Message' => '',
            ]);

            if ($this->filePath) {
                $parser->cleanup($this->filePath);
            }

        } catch (Throwable $e) {
            $this->audioSample->update([
                'status' => AudioSample::STATUS_DRAFT,
                'error_message' => $e->getMessage(),
            ]);

            $run->incrementFailed();

            $this->updateSheet($sheets, [
                'Processing Status' => 'Failed',
                'Audio Sample ID' => (string) $this->audioSample->id,
                'Transcription ID' => '',
                'Error Message' => $e->getMessage(),
            ]);
        }

        event(new AudioSampleProcessed($run->fresh(), $this->audioSample->fresh()));
    }

    public function failed(Throwable $exception): void
    {
        $this->audioSample->update([
            'status' => AudioSample::STATUS_DRAFT,
            'error_message' => $exception->getMessage(),
        ]);

        $this->audioSample->processingRun->incrementFailed();

        $this->updateSheet(app(SheetsService::class), [
            'Processing Status' => 'Failed',
            'Audio Sample ID' => (string) $this->audioSample->id,
            'Transcription ID' => '',
            'Error Message' => $exception->getMessage(),
        ]);
    }

    private function updateSheet(SheetsService $sheets, array $values): void
    {
        if (! $this->sheetContext) {
            return;
        }

        $spreadsheetId = $this->sheetContext['spreadsheet_id'] ?? null;
        $sheetName = $this->sheetContext['sheet_name'] ?? '';
        $rowIndex = $this->sheetContext['row_index'] ?? null;

        if (! $spreadsheetId || ! $rowIndex) {
            return;
        }

        try {
            $sheets->forUser($this->audioSample->processingRun->user);
            $sheets->updateColumns($spreadsheetId, $sheetName, (int) $rowIndex, $values);
        } catch (Throwable) {
            // Ignore sheet update failures
        }
    }
}
