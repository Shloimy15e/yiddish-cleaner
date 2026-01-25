<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Transcription extends Model implements HasMedia
{
    use InteractsWithMedia;

    /**
     * Type constants
     */
    public const TYPE_BASE = 'base';
    public const TYPE_ASR = 'asr';

    public const TYPES = [
        self::TYPE_BASE,
        self::TYPE_ASR,
    ];

    /**
     * Status constants
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
    ];

    /**
     * Source constants
     */
    public const SOURCE_IMPORTED = 'imported';
    public const SOURCE_GENERATED = 'generated';
    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        // Common fields
        'user_id',
        'type',
        'name',
        'audio_sample_id',
        'training_version_id',
        'source',
        'status',
        'notes',
        'error_message',

        // Base transcription fields (text before/after cleaning)
        'text_raw',
        'text_clean',
        'hash_raw',
        'hash_clean',

        // Cleaning metadata (for base type)
        'clean_rate',
        'clean_rate_category',
        'metrics',
        'removals',
        'cleaning_preset',
        'cleaning_mode',

        // Validation fields (for base type)
        'validated_at',
        'validated_by',
        'review_notes',

        // ASR transcription fields (hypothesis)
        'model_name',
        'model_version',
        'hypothesis_text',
        'hypothesis_hash',

        // ASR metrics
        'wer',
        'cer',
        'substitutions',
        'insertions',
        'deletions',
        'reference_words',
        'errors',
    ];

    protected function casts(): array
    {
        return [
            // Cleaning/metrics
            'metrics' => 'array',
            'removals' => 'array',
            'clean_rate' => 'integer',
            'validated_at' => 'datetime',

            // ASR metrics
            'wer' => 'float',
            'cer' => 'float',
            'substitutions' => 'integer',
            'insertions' => 'integer',
            'deletions' => 'integer',
            'reference_words' => 'integer',
            'errors' => 'array',
        ];
    }

    // ==================== Relationships ====================

    public function audioSample(): BelongsTo
    {
        return $this->belongsTo(AudioSample::class);
    }

    public function trainingVersion(): BelongsTo
    {
        return $this->belongsTo(TrainingVersion::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ==================== Media Collections ====================

    public function registerMediaCollections(): void
    {
        // Original reference transcript source file (docx, txt, pdf, etc.) - for base type
        $this->addMediaCollection('source_file')
            ->singleFile()
            ->acceptsMimeTypes([
                'text/plain',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // .docx
                'application/msword', // .doc
                'application/pdf',
            ]);

        // Cleaned transcript file (always .txt) - for base type
        $this->addMediaCollection('cleaned_file')
            ->singleFile()
            ->acceptsMimeTypes(['text/plain']);

        // ASR hypothesis transcript file - for asr type
        $this->addMediaCollection('hypothesis_transcript')
            ->singleFile()
            ->acceptsMimeTypes(['text/plain']);
    }

    // ==================== Scopes ====================

    /**
     * Scope to get only base transcriptions.
     */
    public function scopeBase($query)
    {
        return $query->where('type', self::TYPE_BASE);
    }

    /**
     * Scope to get only ASR transcriptions.
     */
    public function scopeAsr($query)
    {
        return $query->where('type', self::TYPE_ASR);
    }

    /**
     * Scope to get orphan base transcriptions (not linked to any audio sample).
     */
    public function scopeOrphan($query)
    {
        return $query->base()->whereNull('audio_sample_id');
    }

    /**
     * Scope to get linked base transcriptions.
     */
    public function scopeLinked($query)
    {
        return $query->base()->whereNotNull('audio_sample_id');
    }

    /**
     * Scope to get validated base transcriptions.
     */
    public function scopeValidated($query)
    {
        return $query->base()->whereNotNull('validated_at');
    }

    /**
     * Scope to get base transcriptions pending validation.
     */
    public function scopePendingValidation($query)
    {
        return $query->base()
            ->whereNotNull('text_clean')
            ->whereNull('validated_at');
    }

    /**
     * Scope to get base transcriptions that need cleaning.
     */
    public function scopeNeedsCleaning($query)
    {
        return $query->base()
            ->whereNotNull('text_raw')
            ->whereNull('text_clean');
    }

    public function scopeForModel($query, string $modelName)
    {
        return $query->where('model_name', $modelName);
    }

    // ==================== Helper Methods ====================

    /**
     * Check if this is a base transcription.
     */
    public function isBase(): bool
    {
        return $this->type === self::TYPE_BASE;
    }

    /**
     * Check if this is an ASR transcription.
     */
    public function isAsr(): bool
    {
        return $this->type === self::TYPE_ASR;
    }

    /**
     * Check if this transcription is linked to an audio sample.
     */
    public function isLinked(): bool
    {
        return $this->audio_sample_id !== null;
    }

    /**
     * Check if this transcription is orphan (base only).
     */
    public function isOrphan(): bool
    {
        return $this->isBase() && ! $this->isLinked();
    }

    /**
     * Check if the base transcription has raw text ready to clean.
     */
    public function hasRawText(): bool
    {
        return ! empty($this->text_raw);
    }

    /**
     * Check if the base transcription has been cleaned.
     */
    public function isCleaned(): bool
    {
        return ! empty($this->text_clean);
    }

    /**
     * Check if the base transcription is validated.
     */
    public function isValidated(): bool
    {
        return $this->validated_at !== null;
    }

    /**
     * Check if the base transcription can be cleaned.
     */
    public function canBeCleaned(): bool
    {
        return $this->isBase() 
            && $this->hasRawText() 
            && $this->status !== self::STATUS_PROCESSING;
    }

    /**
     * Check if the base transcription can be validated.
     */
    public function canBeValidated(): bool
    {
        return $this->isBase() && $this->isCleaned() && ! $this->isValidated();
    }

    /**
     * Mark base transcription as validated.
     */
    public function markValidated(?string $validatedBy = null, ?string $notes = null): void
    {
        if (! $this->isBase()) {
            throw new \InvalidArgumentException('Only base transcriptions can be validated.');
        }

        $this->update([
            'status' => self::STATUS_COMPLETED,
            'validated_at' => now(),
            'validated_by' => $validatedBy,
            'review_notes' => $notes,
        ]);

        // Sync the linked audio sample's status
        $this->syncAudioSampleStatus();
    }

    /**
     * Remove validation from base transcription.
     */
    public function unvalidate(): void
    {
        if (! $this->isBase()) {
            throw new \InvalidArgumentException('Only base transcriptions can be unvalidated.');
        }

        $this->update([
            'validated_at' => null,
            'validated_by' => null,
            'review_notes' => null,
        ]);

        // Sync the linked audio sample's status
        $this->syncAudioSampleStatus();
    }

    /**
     * Reset cleaning data (for re-cleaning).
     */
    public function resetCleaning(): void
    {
        if (! $this->isBase()) {
            throw new \InvalidArgumentException('Only base transcriptions can be reset for cleaning.');
        }

        $this->clearMediaCollection('cleaned_file');

        $this->update([
            'text_clean' => null,
            'hash_clean' => null,
            'clean_rate' => null,
            'clean_rate_category' => null,
            'metrics' => null,
            'removals' => null,
            'cleaning_preset' => null,
            'cleaning_mode' => null,
            'validated_at' => null,
            'validated_by' => null,
            'review_notes' => null,
            'status' => self::STATUS_PENDING,
        ]);

        // Sync the linked audio sample's status
        $this->syncAudioSampleStatus();
    }

    /**
     * Link this base transcription to an audio sample.
     */
    public function linkToAudioSample(AudioSample $audioSample): void
    {
        if (! $this->isBase()) {
            throw new \InvalidArgumentException('Only base transcriptions can be linked to audio samples.');
        }

        $this->update(['audio_sample_id' => $audioSample->id]);

        // Sync the audio sample's status
        $audioSample->syncStatusFromBaseTranscription();
    }

    /**
     * Unlink this base transcription from its audio sample.
     */
    public function unlinkFromAudioSample(): void
    {
        if (! $this->isBase()) {
            throw new \InvalidArgumentException('Only base transcriptions can be unlinked.');
        }

        $audioSample = $this->audioSample;
        $this->update(['audio_sample_id' => null]);

        // Sync the audio sample's status (now has no base)
        if ($audioSample) {
            $audioSample->syncStatusFromBaseTranscription();
        }
    }

    /**
     * Sync the linked audio sample's status based on this transcription's state.
     */
    protected function syncAudioSampleStatus(): void
    {
        if ($this->isLinked()) {
            $this->audioSample->syncStatusFromBaseTranscription();
        }
    }

    // ==================== Computed Attributes ====================

    public function getTotalErrorsAttribute(): int
    {
        return ($this->substitutions ?? 0) + ($this->insertions ?? 0) + ($this->deletions ?? 0);
    }

    public function getWordCountAttribute(): int
    {
        return $this->metrics['word_count'] ?? 0;
    }

    // ==================== Boot ====================

    protected static function boot()
    {
        parent::boot();

        // Validate that ASR type has audio_sample_id
        static::saving(function (Transcription $transcription) {
            if ($transcription->type === self::TYPE_ASR && $transcription->audio_sample_id === null) {
                throw new \InvalidArgumentException('ASR transcriptions must be linked to an audio sample.');
            }
        });
    }
}
