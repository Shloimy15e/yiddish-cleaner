<?php

namespace App\Jobs;

use App\Models\AudioSample;
use App\Models\Transcription;
use App\Models\User;
use App\Services\Alignment\AlignmentManager;
use App\Services\Asr\AsrWord;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Generate word alignment for a transcription using forced alignment models.
 * 
 * This job takes existing transcription text and aligns it to the audio,
 * creating word-level timing data (TranscriptionWord records).
 */
class AlignTranscriptionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600; // 10 minutes max

    /**
     * Create a new job instance.
     *
     * @param  Transcription  $transcription  The transcription to align
     * @param  string  $provider  Alignment provider (e.g., 'local_whisperx', 'whisperx')
     * @param  string|null  $model  Specific model to use (optional)
     * @param  int|null  $userId  User ID for API credential lookup
     * @param  bool  $overwrite  Whether to overwrite existing word data
     */
    public function __construct(
        public Transcription $transcription,
        public string $provider = 'local_whisperx',
        public ?string $model = null,
        public ?int $userId = null,
        public bool $overwrite = false,
    ) {}

    public function handle(AlignmentManager $alignmentManager): void
    {
        try {
            Log::info("Starting alignment for Transcription #{$this->transcription->id}", [
                'provider' => $this->provider,
                'model' => $this->model,
                'attempt' => $this->transcription->alignment_attempts + 1,
            ]);

            // Check if transcription already has words and overwrite is false
            if (! $this->overwrite && $this->transcription->hasWordData()) {
                Log::info("Transcription #{$this->transcription->id} already has word data, skipping alignment");
                $this->transcription->markAlignmentNotNeeded();
                return;
            }

            // Mark alignment as started
            $this->transcription->markAlignmentStarted($this->provider, $this->model);

            // Get the text to align
            $text = $this->getTextToAlign();
            if (empty($text)) {
                throw new \RuntimeException('No text available to align');
            }

            // Get the audio sample
            $audioSample = $this->transcription->audioSample;
            if (! $audioSample) {
                throw new \RuntimeException('Transcription must be linked to an audio sample for alignment');
            }

            // Get audio file path
            $audioMedia = $audioSample->getFirstMedia('audio');
            if (! $audioMedia) {
                throw new \RuntimeException('No audio file attached to the audio sample');
            }

            // Handle both local and remote storage
            $tempAudioPath = null;
            $audioPath = $audioMedia->getPath();

            if (! file_exists($audioPath)) {
                // Media is on remote storage - download to temp file
                $tempDir = storage_path('app/temp');
                if (! is_dir($tempDir)) {
                    mkdir($tempDir, 0755, true);
                }

                $tempAudioPath = $tempDir . '/align_audio_' . $this->transcription->id . '_' . uniqid() . '.' . $audioMedia->extension;
                $disk = Storage::disk($audioMedia->disk);

                $stream = $disk->readStream($audioMedia->getPathRelativeToRoot());
                if (! $stream) {
                    throw new \RuntimeException("Could not read audio file from storage: {$audioMedia->getPathRelativeToRoot()}");
                }

                file_put_contents($tempAudioPath, stream_get_contents($stream));
                fclose($stream);

                $audioPath = $tempAudioPath;

                Log::info('Downloaded remote audio file for alignment', [
                    'transcription_id' => $this->transcription->id,
                    'temp_path' => $tempAudioPath,
                ]);
            }

            // Get API credential for the provider (only if required)
            $user = $this->userId ? User::find($this->userId) : null;
            $credential = null;

            // Check if provider requires credentials
            $requiresCredential = $alignmentManager->requiresCredential($this->provider);

            if ($requiresCredential) {
                $credential = $user?->getApiCredential($this->provider, 'alignment');

                // Fallback to ASR credential if no alignment-specific credential
                if (! $credential) {
                    $credential = $user?->getApiCredential($this->provider, 'asr');
                }

                if (! $credential) {
                    throw new \RuntimeException("No API key configured for {$this->provider}");
                }
            }

            // Perform alignment
            $result = $alignmentManager->align(
                audioPath: $audioPath,
                text: $text,
                provider: $this->provider,
                credential: $credential,
                model: $this->model,
                options: [
                    'name' => $audioSample->name,
                    'language' => 'yi', // Yiddish
                ],
            );

            // Convert AlignedWord to AsrWord for storage
            $asrWords = [];
            foreach ($result->words as $alignedWord) {
                $asrWords[] = new AsrWord(
                    word: $alignedWord->word,
                    start: $alignedWord->start,
                    end: $alignedWord->end,
                    confidence: $alignedWord->confidence,
                );
            }

            // Store word-level data
            if (! empty($asrWords)) {
                $this->transcription->storeWords($asrWords);
                Log::info("Stored {$result->getWordCount()} aligned words for Transcription #{$this->transcription->id}");
                
                // Mark alignment as completed
                $this->transcription->markAlignmentCompleted();
            } else {
                Log::warning("Alignment completed but no words returned for Transcription #{$this->transcription->id}");
                $this->transcription->markAlignmentFailed('Alignment completed but no words were returned');
            }

            // Update transcription status if not already completed
            if ($this->transcription->status !== Transcription::STATUS_COMPLETED) {
                $this->transcription->update([
                    'status' => Transcription::STATUS_COMPLETED,
                ]);
            }

            // Clean up temporary audio file
            if ($tempAudioPath && file_exists($tempAudioPath)) {
                unlink($tempAudioPath);
            }

            Log::info("Alignment completed for Transcription #{$this->transcription->id}", [
                'word_count' => $result->getWordCount(),
                'average_confidence' => $result->getAverageConfidence(),
            ]);

        } catch (Throwable $e) {
            // Clean up temporary audio file on error
            if (isset($tempAudioPath) && $tempAudioPath && file_exists($tempAudioPath)) {
                unlink($tempAudioPath);
            }

            // Mark alignment as failed with error details
            $this->transcription->markAlignmentFailed($e->getMessage());

            Log::error("Alignment failed for Transcription #{$this->transcription->id}", [
                'error' => $e->getMessage(),
                'provider' => $this->provider,
                'model' => $this->model,
                'attempts' => $this->transcription->alignment_attempts,
            ]);

            throw $e;
        }
    }

    /**
     * Get the text to align based on transcription type.
     */
    protected function getTextToAlign(): string
    {
        // For ASR transcriptions, use the hypothesis text
        if ($this->transcription->type === Transcription::TYPE_ASR) {
            return $this->transcription->hypothesis_text ?? '';
        }

        // For base transcriptions, prefer clean text, fallback to raw
        return $this->transcription->text_clean
            ?? $this->transcription->text_raw
            ?? '';
    }

    public function failed(Throwable $exception): void
    {
        Log::error("AlignTranscriptionJob failed for Transcription #{$this->transcription->id}", [
            'error' => $exception->getMessage(),
            'provider' => $this->provider,
            'model' => $this->model,
            'attempts' => $this->transcription->alignment_attempts,
        ]);

        // Ensure alignment is marked as failed even if exception was thrown during cleanup
        if ($this->transcription->alignment_status !== Transcription::ALIGNMENT_FAILED) {
            $this->transcription->markAlignmentFailed($exception->getMessage());
        }
    }
}
