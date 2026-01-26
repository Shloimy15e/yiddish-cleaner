<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class AudioSample extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    /**
     * Status constants for the workflow.
     * 
     * draft: Initial/incomplete sample
     * pending_base: Has audio, no base transcription linked
     * unclean: Base transcription linked but not validated
     * ready: Base validated, can run ASR benchmarks
     * benchmarked: Has completed ASR transcriptions
     */
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING_BASE = 'pending_base';
    public const STATUS_UNCLEAN = 'unclean';
    public const STATUS_READY = 'ready';
    public const STATUS_BENCHMARKED = 'benchmarked';

    /**
     * All valid status values.
     */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING_BASE,
        self::STATUS_UNCLEAN,
        self::STATUS_READY,
        self::STATUS_BENCHMARKED,
    ];

    protected $fillable = [
        'user_id',
        'processing_run_id',
        'name',
        'source_url',
        'audio_duration_seconds',
        'status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'audio_duration_seconds' => 'float',
        ];
    }

    // ==================== Relationships ====================

    public function processingRun(): BelongsTo
    {
        return $this->belongsTo(ProcessingRun::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function trainingVersions(): BelongsToMany
    {
        return $this->belongsToMany(TrainingVersion::class, 'audio_sample_training_version')
            ->withTimestamps();
    }

    /**
     * Get the base transcription (reference/ground truth) for this audio sample.
     */
    public function baseTranscription(): HasOne
    {
        return $this->hasOne(Transcription::class)->where('type', Transcription::TYPE_BASE);
    }

    /**
     * Get all ASR transcriptions (hypothesis) for this audio sample.
     */
    public function asrTranscriptions(): HasMany
    {
        return $this->hasMany(Transcription::class)->where('type', Transcription::TYPE_ASR);
    }

    /**
     * Get all transcriptions (both base and ASR).
     */
    public function transcriptions(): HasMany
    {
        return $this->hasMany(Transcription::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(AudioSampleStatusHistory::class)->orderByDesc('created_at');
    }

    // ==================== Media Collections ====================

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('audio')
            ->singleFile()
            ->acceptsMimeTypes([
                'audio/mpeg',      // .mp3
                'audio/mp3',       // .mp3 (alternative)
                'audio/wav',       // .wav
                'audio/x-wav',     // .wav (alternative)
                'audio/ogg',       // .ogg
                'audio/flac',      // .flac
                'audio/x-flac',    // .flac (alternative)
                'audio/mp4',       // .m4a
                'audio/x-m4a',     // .m4a (alternative)
                'audio/aac',       // .aac
                'video/mp4',       // .m4a detected as video/mp4
            ]);

        // Legacy collection - kept for migration to copy media to Transcription model
        // Can be removed after migration runs successfully
        $this->addMediaCollection('reference_transcript')
            ->singleFile()
            ->acceptsMimeTypes([
                'text/plain',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // .docx
                'application/msword', // .doc
                'application/pdf',
            ]);
    }

    // ==================== Status Helpers ====================

    /**
     * Check if the sample has audio uploaded.
     */
    public function hasAudio(): bool
    {
        return $this->hasMedia('audio');
    }

    /**
     * Check if the sample has a base transcription linked.
     */
    public function hasBaseTranscription(): bool
    {
        return $this->baseTranscription()->exists();
    }

    /**
     * Check if the base transcription is validated (ready for benchmarking).
     */
    public function isBaseValidated(): bool
    {
        $base = $this->baseTranscription;
        return $base && $base->isValidated();
    }

    /**
     * Check if the sample is ready for ASR benchmarking.
     */
    public function isReadyForBenchmark(): bool
    {
        return $this->hasAudio() && $this->isBaseValidated();
    }

    /**
     * Check if the sample has any completed ASR transcriptions.
     */
    public function hasBenchmarks(): bool
    {
        return $this->asrTranscriptions()
            ->where('status', Transcription::STATUS_COMPLETED)
            ->exists();
    }

    /**
     * Sync the status based on the current state of the base transcription.
     * Called automatically when base transcription is linked/unlinked/validated.
     */
    public function syncStatusFromBaseTranscription(): void
    {
        $previousStatus = $this->status;
        $newStatus = $this->calculateStatus();

        if ($previousStatus !== $newStatus) {
            $this->update(['status' => $newStatus]);

            // Log status history
            AudioSampleStatusHistory::log(
                audioSample: $this,
                action: AudioSampleStatusHistory::ACTION_STATUS_CHANGED,
                fromStatus: $previousStatus,
                toStatus: $newStatus,
                notes: 'Auto-synced from base transcription state',
            );
        }
    }

    /**
     * Calculate the appropriate status based on current state.
     */
    protected function calculateStatus(): string
    {
        // Check for benchmarked status first
        if ($this->hasBenchmarks()) {
            return self::STATUS_BENCHMARKED;
        }

        // Check base transcription state
        $base = $this->baseTranscription;

        if (! $base) {
            return self::STATUS_PENDING_BASE;
        }

        if ($base->isValidated()) {
            return self::STATUS_READY;
        }

        return self::STATUS_UNCLEAN;
    }

    // ==================== Scopes ====================

    /**
     * Samples without a base transcription.
     */
    public function scopePendingBase($query)
    {
        return $query->where('status', self::STATUS_PENDING_BASE);
    }

    /**
     * Samples with unvalidated base transcription.
     */
    public function scopeUnclean($query)
    {
        return $query->where('status', self::STATUS_UNCLEAN);
    }

    /**
     * Samples ready for ASR benchmarking.
     */
    public function scopeReady($query)
    {
        return $query->where('status', self::STATUS_READY);
    }

    /**
     * Samples that are benchmark ready (alias for ready).
     */
    public function scopeBenchmarkReady($query)
    {
        return $query->where('status', self::STATUS_READY);
    }

    /**
     * Samples that have been benchmarked.
     */
    public function scopeBenchmarked($query)
    {
        return $query->where('status', self::STATUS_BENCHMARKED);
    }

    /**
     * Samples with audio uploaded.
     */
    public function scopeWithAudio($query)
    {
        return $query->whereHas('media', function ($q) {
            $q->where('collection_name', 'audio');
        });
    }
}
