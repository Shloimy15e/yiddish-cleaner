<?php

namespace App\Jobs;

use App\Models\AudioSample;
use App\Models\Transcription;
use App\Models\User;
use App\Services\Asr\AsrManager;
use App\Services\Asr\WerCalculator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Transcribe an audio sample using ASR (async job).
 */
class TranscribeAudioSampleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 900; // 15 minutes max

    public function __construct(
        public AudioSample $audioSample,
        public string $provider = 'yiddishlabs',
        public ?string $model = null,
        public ?string $notes = null,
        public ?int $userId = null,
        public ?int $transcriptionId = null,
    ) {}

    public function handle(AsrManager $asrManager, WerCalculator $werCalculator): void
    {
        try {
            Log::info("Starting ASR transcription for AudioSample #{$this->audioSample->id}", [
                'provider' => $this->provider,
                'model' => $this->model,
            ]);

            $transcription = $this->transcriptionId
                ? Transcription::find($this->transcriptionId)
                : null;

            if ($transcription) {
                $transcription->update([
                    'status' => Transcription::STATUS_PROCESSING,
                ]);
            }

            // Get audio file path
            $audioMedia = $this->audioSample->getFirstMedia('audio');
            if (! $audioMedia) {
                throw new \RuntimeException('No audio file attached to this sample');
            }

            // Handle both local and remote storage (S3, etc.)
            // For remote storage, getPath() returns a relative path, so we need to download the file
            $tempAudioPath = null;
            $audioPath = $audioMedia->getPath();

            if (! file_exists($audioPath)) {
                // Media is on remote storage - download to temp file
                $tempDir = storage_path('app/temp');
                if (! is_dir($tempDir)) {
                    mkdir($tempDir, 0755, true);
                }

                $tempAudioPath = $tempDir.'/audio_'.$this->audioSample->id.'_'.uniqid().'.'.$audioMedia->extension;
                $disk = Storage::disk($audioMedia->disk);
                
                $stream = $disk->readStream($audioMedia->getPathRelativeToRoot());
                if (! $stream) {
                    throw new \RuntimeException("Could not read audio file from storage: {$audioMedia->getPathRelativeToRoot()}");
                }
                
                file_put_contents($tempAudioPath, stream_get_contents($stream));
                fclose($stream);
                
                $audioPath = $tempAudioPath;
                
                Log::info("Downloaded remote audio file to temp path", [
                    'audio_sample_id' => $this->audioSample->id,
                    'temp_path' => $tempAudioPath,
                ]);
            }

            // Get API credential for the provider
            $user = $this->userId ? User::find($this->userId) : null;
            $credential = $user?->getApiCredential($this->provider, 'asr');

            if (! $credential) {
                throw new \RuntimeException("No API key configured for {$this->provider}");
            }

            // Perform transcription
            $result = $asrManager->transcribe(
                audioPath: $audioPath,
                provider: $this->provider,
                credential: $credential,
                model: $this->model,
                options: [
                    'name' => $this->audioSample->name,
                ],
            );

            // Calculate WER/CER against reference
            $referenceText = $this->audioSample->baseTranscription?->text_clean;
            $werResult = null;

            if ($referenceText) {
                $werResult = $werCalculator->calculate($referenceText, $result->text);
            }

            // Update Transcription record
            if (! $transcription) {
                $transcription = Transcription::create([
                    'user_id' => $this->userId,
                    'audio_sample_id' => $this->audioSample->id,
                    'model_name' => $result->provider.'/'.$result->model,
                    'model_version' => $result->model,
                    'source' => 'generated',
                    'status' => Transcription::STATUS_PROCESSING,
                    'notes' => $this->notes,
                ]);
            }

            $transcription->update([
                'model_name' => $result->provider.'/'.$result->model,
                'model_version' => $result->model,
                'source' => $transcription->source ?? 'generated',
                'hypothesis_text' => $result->text,
                'hypothesis_hash' => $result->getTextHash(),
                'wer' => $werResult?->wer,
                'cer' => $werResult?->cer,
                'substitutions' => $werResult?->substitutions ?? 0,
                'insertions' => $werResult?->insertions ?? 0,
                'deletions' => $werResult?->deletions ?? 0,
                'reference_words' => $werResult?->referenceWords ?? 0,
                'errors' => $werResult?->errors ?? [],
                'status' => Transcription::STATUS_COMPLETED,
            ]);

            // Store word-level data if available
            if ($result->hasWords()) {
                $transcription->storeWords($result->words);
                Log::info("Stored {$result->getWordCount()} words for Transcription #{$transcription->id}");
            }

            // Save transcript as media file
            $tempPath = storage_path('app/temp/transcript_'.$transcription->id.'.txt');
            if (! is_dir(dirname($tempPath))) {
                mkdir(dirname($tempPath), 0755, true);
            }
            file_put_contents($tempPath, $result->text);
            $transcription->addMedia($tempPath)
                ->usingFileName('hypothesis.txt')
                ->toMediaCollection('hypothesis_transcript');

            Log::info("ASR transcription completed for AudioSample #{$this->audioSample->id}", [
                'transcription_id' => $transcription->id,
                'wer' => $werResult?->wer,
                'cer' => $werResult?->cer,
            ]);

            // Clean up temporary audio file if we downloaded it
            if ($tempAudioPath && file_exists($tempAudioPath)) {
                unlink($tempAudioPath);
            }

            // Broadcast event for real-time updates (optional)
            // event(new TranscriptionCompleted($transcription));

        } catch (Throwable $e) {
            // Clean up temporary audio file on error
            if (isset($tempAudioPath) && $tempAudioPath && file_exists($tempAudioPath)) {
                unlink($tempAudioPath);
            }

            if ($transcription) {
                $transcription->update([
                    'status' => Transcription::STATUS_FAILED,
                    'error_message' => $e->getMessage(),
                ]);
            } elseif ($this->transcriptionId) {
                Transcription::whereKey($this->transcriptionId)->update([
                    'status' => Transcription::STATUS_FAILED,
                    'error_message' => $e->getMessage(),
                ]);
            }

            Log::error("ASR transcription failed for AudioSample #{$this->audioSample->id}", [
                'error' => $e->getMessage(),
                'provider' => $this->provider,
            ]);

            if ($this->transcriptionId) {
                Transcription::whereKey($this->transcriptionId)->update([
                    'status' => Transcription::STATUS_FAILED,
                ]);
            }

            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error("TranscribeAudioSampleJob failed for AudioSample #{$this->audioSample->id}", [
            'error' => $exception->getMessage(),
        ]);

        if ($this->transcriptionId) {
            Transcription::whereKey($this->transcriptionId)->update([
                'status' => Transcription::STATUS_FAILED,
                'error_message' => $exception->getMessage(),
            ]);
        }
    }
}
