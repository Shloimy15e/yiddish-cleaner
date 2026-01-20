<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class AudioSample extends Model implements HasMedia
{
    use InteractsWithMedia;

    /**
     * Status constants for the workflow.
     */
    public const STATUS_PENDING_TRANSCRIPT = 'pending_transcript';
    public const STATUS_IMPORTED = 'imported';
    public const STATUS_CLEANING = 'cleaning';
    public const STATUS_CLEANED = 'cleaned';
    public const STATUS_VALIDATED = 'validated';
    public const STATUS_FAILED = 'failed';

    /**
     * All valid status values.
     */
    public const STATUSES = [
        self::STATUS_PENDING_TRANSCRIPT,
        self::STATUS_IMPORTED,
        self::STATUS_CLEANING,
        self::STATUS_CLEANED,
        self::STATUS_VALIDATED,
        self::STATUS_FAILED,
    ];

    protected $fillable = [
        'processing_run_id',
        'name',
        'source_url',
        'reference_hash_raw',
        'reference_hash_clean',
        'reference_text_raw',
        'reference_text_clean',
        'audio_duration_seconds',
        'clean_rate',
        'clean_rate_category',
        'metrics',
        'removals',
        'status',
        'error_message',
        'validated_at',
        'validated_by',
        'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'metrics' => 'array',
            'removals' => 'array',
            'audio_duration_seconds' => 'float',
            'clean_rate' => 'integer',
            'validated_at' => 'datetime',
        ];
    }

    public function processingRun(): BelongsTo
    {
        return $this->belongsTo(ProcessingRun::class);
    }

    public function trainingVersions(): BelongsToMany
    {
        return $this->belongsToMany(TrainingVersion::class, 'audio_sample_training_version')
            ->withTimestamps();
    }

    public function transcriptions(): HasMany
    {
        return $this->hasMany(Transcription::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('audio')
            ->singleFile()
            ->acceptsMimeTypes(['audio/*']);

        // Original reference transcript (docx, txt, pdf, etc.)
        $this->addMediaCollection('reference_transcript')
            ->singleFile()
            ->acceptsMimeTypes([
                'text/plain',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // .docx
                'application/msword', // .doc
                'application/pdf',
            ]);

        // Cleaned reference transcript (always .txt)
        $this->addMediaCollection('cleaned_transcript')
            ->singleFile()
            ->acceptsMimeTypes(['text/plain']);
    }

    /**
     * Check if the sample has a raw transcript ready to clean.
     */
    public function hasRawTranscript(): bool
    {
        return ! empty($this->reference_text_raw);
    }

    /**
     * Check if the sample has been cleaned.
     */
    public function isCleaned(): bool
    {
        return ! empty($this->reference_text_clean);
    }

    /**
     * Check if the sample is validated (benchmark ready).
     */
    public function isValidated(): bool
    {
        return $this->validated_at !== null;
    }

    /**
     * Check if the sample can be cleaned.
     */
    public function canBeCleaned(): bool
    {
        return $this->hasRawTranscript() 
            && ! in_array($this->status, [self::STATUS_CLEANING, self::STATUS_PENDING_TRANSCRIPT]);
    }

    /**
     * Check if the sample can be validated.
     */
    public function canBeValidated(): bool
    {
        return $this->isCleaned() && ! $this->isValidated();
    }

    /**
     * Mark sample as validated (benchmark ready).
     */
    public function validate(?string $validatedBy = null, ?string $notes = null): void
    {
        $this->update([
            'status' => self::STATUS_VALIDATED,
            'validated_at' => now(),
            'validated_by' => $validatedBy,
            'review_notes' => $notes,
        ]);
    }

    /**
     * Remove validation status.
     */
    public function unvalidate(): void
    {
        $this->update([
            'status' => self::STATUS_CLEANED,
            'validated_at' => null,
            'validated_by' => null,
            'review_notes' => null,
        ]);
    }

    /**
     * Reset cleaning data (for re-cleaning).
     */
    public function resetCleaning(): void
    {
        // Clear the cleaned transcript media
        $this->clearMediaCollection('cleaned_transcript');

        $this->update([
            'reference_text_clean' => null,
            'reference_hash_clean' => null,
            'clean_rate' => null,
            'clean_rate_category' => null,
            'metrics' => null,
            'removals' => null,
            'status' => self::STATUS_IMPORTED,
            'validated_at' => null,
            'validated_by' => null,
            'review_notes' => null,
        ]);
    }

    // ==================== Scopes ====================

    public function scopeValidated($query)
    {
        return $query->whereNotNull('validated_at');
    }

    /**
     * Samples that are cleaned but not yet validated.
     */
    public function scopePendingValidation($query)
    {
        return $query->whereNull('validated_at')
            ->where('status', self::STATUS_CLEANED);
    }

    /**
     * Samples that need cleaning (imported but not cleaned).
     */
    public function scopeNeedsCleaning($query)
    {
        return $query->where('status', self::STATUS_IMPORTED);
    }

    /**
     * Samples that need a transcript uploaded.
     */
    public function scopeNeedsTranscript($query)
    {
        return $query->where('status', self::STATUS_PENDING_TRANSCRIPT);
    }

    /**
     * Samples that are benchmark ready (validated).
     */
    public function scopeBenchmarkReady($query)
    {
        return $query->where('status', self::STATUS_VALIDATED);
    }

    public function scopeWithMinCleanRate($query, int $minRate)
    {
        return $query->where('clean_rate', '>=', $minRate);
    }

    public function getWordCountAttribute(): int
    {
        return $this->metrics['word_count'] ?? 0;
    }
}
