<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Transcription extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

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
     * Alignment status constants
     */
    public const ALIGNMENT_PENDING = 'pending';

    public const ALIGNMENT_PROCESSING = 'processing';

    public const ALIGNMENT_COMPLETED = 'completed';

    public const ALIGNMENT_FAILED = 'failed';

    public const ALIGNMENT_NOT_NEEDED = 'not_needed';

    public const ALIGNMENT_STATUSES = [
        self::ALIGNMENT_PENDING,
        self::ALIGNMENT_PROCESSING,
        self::ALIGNMENT_COMPLETED,
        self::ALIGNMENT_FAILED,
        self::ALIGNMENT_NOT_NEEDED,
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

        // WER calculation range (word indices, 0-based, inclusive)
        'wer_ref_start',
        'wer_ref_end',
        'wer_hyp_start',
        'wer_hyp_end',

        // Training flag
        'flagged_for_training',

        // Alignment tracking
        'alignment_status',
        'alignment_error',
        'alignment_provider',
        'alignment_model',
        'alignment_started_at',
        'alignment_completed_at',
        'alignment_attempts',
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

            // WER calculation range
            'wer_ref_start' => 'integer',
            'wer_ref_end' => 'integer',
            'wer_hyp_start' => 'integer',
            'wer_hyp_end' => 'integer',

            // Training
            'flagged_for_training' => 'boolean',

            // Alignment
            'alignment_started_at' => 'datetime',
            'alignment_completed_at' => 'datetime',
            'alignment_attempts' => 'integer',
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

    public function words(): HasMany
    {
        return $this->hasMany(TranscriptionWord::class)->orderBy('word_index');
    }

    public function segments(): HasMany
    {
        return $this->hasMany(TranscriptionSegment::class)->orderBy('segment_index');
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

    /**
     * Scope to get transcriptions needing alignment.
     */
    public function scopeNeedsAlignment($query)
    {
        return $query->whereNull('alignment_status')
            ->orWhere('alignment_status', self::ALIGNMENT_PENDING)
            ->orWhere('alignment_status', self::ALIGNMENT_FAILED);
    }

    /**
     * Scope to get transcriptions with alignment in progress.
     */
    public function scopeAligning($query)
    {
        return $query->where('alignment_status', self::ALIGNMENT_PROCESSING);
    }

    /**
     * Scope to get aligned transcriptions.
     */
    public function scopeAligned($query)
    {
        return $query->where('alignment_status', self::ALIGNMENT_COMPLETED);
    }

    /**
     * Scope to get transcriptions with failed alignment.
     */
    public function scopeAlignmentFailed($query)
    {
        return $query->where('alignment_status', self::ALIGNMENT_FAILED);
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

    // ==================== Alignment Methods ====================

    /**
     * Check if alignment is needed (has text but no word data).
     */
    public function needsAlignment(): bool
    {
        if ($this->hasWordData()) {
            return false;
        }

        // Check if there's text to align
        if ($this->isAsr()) {
            return ! empty($this->hypothesis_text);
        }

        return ! empty($this->text_clean) || ! empty($this->text_raw);
    }

    /**
     * Check if alignment is currently in progress.
     */
    public function isAligning(): bool
    {
        return $this->alignment_status === self::ALIGNMENT_PROCESSING;
    }

    /**
     * Check if alignment has failed.
     */
    public function hasAlignmentFailed(): bool
    {
        return $this->alignment_status === self::ALIGNMENT_FAILED;
    }

    /**
     * Check if alignment is completed.
     */
    public function isAligned(): bool
    {
        return $this->alignment_status === self::ALIGNMENT_COMPLETED && $this->hasWordData();
    }

    /**
     * Check if alignment can be retried.
     */
    public function canRetryAlignment(): bool
    {
        return $this->hasAlignmentFailed()
            || ($this->needsAlignment() && ! $this->isAligning());
    }

    /**
     * Mark alignment as started.
     */
    public function markAlignmentStarted(string $provider, ?string $model = null): void
    {
        $this->update([
            'alignment_status' => self::ALIGNMENT_PROCESSING,
            'alignment_error' => null,
            'alignment_provider' => $provider,
            'alignment_model' => $model,
            'alignment_started_at' => now(),
            'alignment_completed_at' => null,
            'alignment_attempts' => $this->alignment_attempts + 1,
        ]);
    }

    /**
     * Mark alignment as completed successfully.
     */
    public function markAlignmentCompleted(): void
    {
        $this->update([
            'alignment_status' => self::ALIGNMENT_COMPLETED,
            'alignment_error' => null,
            'alignment_completed_at' => now(),
        ]);
    }

    /**
     * Mark alignment as failed.
     */
    public function markAlignmentFailed(string $error): void
    {
        $this->update([
            'alignment_status' => self::ALIGNMENT_FAILED,
            'alignment_error' => $error,
            'alignment_completed_at' => now(),
        ]);
    }

    /**
     * Mark alignment as not needed (e.g., already has word data from ASR).
     */
    public function markAlignmentNotNeeded(): void
    {
        $this->update([
            'alignment_status' => self::ALIGNMENT_NOT_NEEDED,
            'alignment_error' => null,
        ]);
    }

    /**
     * Reset alignment status for retry.
     */
    public function resetAlignmentStatus(): void
    {
        $this->update([
            'alignment_status' => self::ALIGNMENT_PENDING,
            'alignment_error' => null,
            'alignment_started_at' => null,
            'alignment_completed_at' => null,
        ]);
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

    /**
     * Get the count of critical substitutions from word review (respects custom range).
     *
     * Counts words flagged is_critical_error=true that are NOT deleted or inserted.
     * A correction (corrected_word) is NOT required — the critical flag alone
     * is sufficient to count as a critical substitution in Custom WER.
     */
    public function getCustomWerCriticalReplacementCountAttribute(): int
    {
        if (isset($this->attributes['_critical_replacement_cache'])) {
            return $this->attributes['_critical_replacement_cache'];
        }

        $query = $this->words()
            ->where('is_critical_error', true)
            ->where('is_deleted', false)
            ->where('is_inserted', false);

        // Apply custom range if set (using hypothesis range)
        if ($this->wer_hyp_start !== null || $this->wer_hyp_end !== null) {
            $start = $this->wer_hyp_start ?? 0;
            $end = $this->wer_hyp_end;

            $query->where('word_index', '>=', $start);
            if ($end !== null) {
                $query->where('word_index', '<=', $end);
            }
        }

        $count = $query->count();
        $this->attributes['_critical_replacement_cache'] = $count;

        return $count;
    }

    /**
     * Get insertions count for Custom WER (from standard Levenshtein).
     */
    public function getCustomWerInsertionCountAttribute(): int
    {
        return $this->insertions ?? 0;
    }

    /**
     * Get deletions count for Custom WER (from standard Levenshtein).
     */
    public function getCustomWerDeletionCountAttribute(): int
    {
        return $this->deletions ?? 0;
    }

    /**
     * Get total substitution count for Custom WER display (from standard Levenshtein).
     *
     * This is ALL substitutions from the automated comparison, shown for context.
     * Only critical replacements (from manual review) count toward the rate.
     */
    public function getCustomWerReplacementCountAttribute(): int
    {
        return $this->substitutions ?? 0;
    }

    /**
     * Get reference word count used as denominator (from standard Levenshtein).
     */
    public function getReviewedWordCountAttribute(): int
    {
        return $this->reference_words ?? 0;
    }

    /**
     * Get the total Custom WER error count.
     *
     * Uses Levenshtein insertions + deletions + manual critical replacements.
     */
    public function getCustomWerErrorCountAttribute(): int
    {
        return $this->custom_wer_insertion_count
            + $this->custom_wer_deletion_count
            + $this->custom_wer_critical_replacement_count;
    }

    /**
     * Get the Custom Word Error Rate.
     *
     * Custom WER = (levenshtein_insertions + levenshtein_deletions + critical_replacements) / reference_words × 100
     *
     * Uses the standard Levenshtein WER as a base but only counts substitutions
     * that are marked as critical errors during manual review.
     *
     * Returns null if no standard WER data exists (reference_words = 0).
     */
    public function getCustomWerAttribute(): ?float
    {
        $referenceWords = $this->reference_words;

        if (! $referenceWords) {
            return null;
        }

        return round(($this->custom_wer_error_count / $referenceWords) * 100, 2);
    }

    // ==================== Word-Level Methods ====================

    /**
     * Check if this transcription has word-level data.
     */
    public function hasWordData(): bool
    {
        return $this->words()->exists();
    }

    /**
     * Get the count of corrected words.
     */
    public function getCorrectionCount(): int
    {
        return $this->words()
            ->where(function ($q) {
                $q->whereNotNull('corrected_word')
                    ->orWhere('is_deleted', true)
                    ->orWhere('is_inserted', true);
            })
            ->count();
    }

    /**
     * Get the correction rate (corrected words / total words).
     */
    public function getCorrectionRate(): ?float
    {
        $totalWords = $this->words()->original()->count();

        if ($totalWords === 0) {
            return null;
        }

        return $this->getCorrectionCount() / $totalWords;
    }

    /**
     * Get the corrected text (applying all word corrections).
     */
    public function getCorrectedText(): string
    {
        if (! $this->hasWordData()) {
            return $this->hypothesis_text ?? '';
        }

        $words = $this->words()
            ->active()
            ->orderBy('word_index')
            ->get();

        return $words
            ->map(fn (TranscriptionWord $word) => $word->getDisplayWord())
            ->filter(fn ($word) => $word !== '')
            ->implode(' ');
    }

    /**
     * Store word-level data from ASR result (legacy).
     *
     * @param  \App\Services\Asr\AsrWord[]  $asrWords
     */
    public function storeWords(array $asrWords): void
    {
        if (empty($asrWords)) {
            return;
        }

        try {
            // Clear existing words
            $this->words()->delete();

            // Insert new words
            foreach ($asrWords as $index => $asrWord) {
                $this->words()->create([
                    'word_index' => $index,
                    'word' => $asrWord->word,
                    'start_time' => $asrWord->start,
                    'end_time' => $asrWord->end,
                    'confidence' => $asrWord->confidence,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to store word-level data for transcription, continuing without words', [
                'transcription_id' => $this->id,
                'error' => $e->getMessage(),
                'word_count' => count($asrWords),
            ]);
            // Don't re-throw - transcription is still valid without word data
        }
    }

    /**
     * Store segment-level data from ASR result.
     *
     * @param  \App\Services\Asr\AsrSegment[]  $asrSegments
     */
    public function storeSegments(array $asrSegments): void
    {
        if (empty($asrSegments)) {
            return;
        }

        try {
            // Clear existing segments
            $this->segments()->delete();

            // Insert new segments
            foreach ($asrSegments as $index => $asrSegment) {
                $this->segments()->create([
                    'segment_index' => $index,
                    'text' => $asrSegment->text,
                    'start_time' => $asrSegment->start,
                    'end_time' => $asrSegment->end,
                    'confidence' => $asrSegment->confidence,
                    'words_json' => $asrSegment->words,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to store segment-level data for transcription, continuing without segments', [
                'transcription_id' => $this->id,
                'error' => $e->getMessage(),
                'segment_count' => count($asrSegments),
            ]);
            // Don't re-throw - transcription is still valid without segment data
        }
    }

    // ==================== Segment-Level Methods ====================

    /**
     * Check if this transcription has segment-level data.
     */
    public function hasSegmentData(): bool
    {
        return $this->segments()->exists();
    }

    /**
     * Get the count of corrected segments.
     */
    public function getSegmentCorrectionCount(): int
    {
        return $this->segments()
            ->whereNotNull('corrected_text')
            ->count();
    }

    /**
     * Get the corrected text from segments (applying all segment corrections).
     */
    public function getCorrectedTextFromSegments(): string
    {
        if (! $this->hasSegmentData()) {
            return $this->hypothesis_text ?? '';
        }

        $segments = $this->segments()
            ->orderBy('segment_index')
            ->get();

        return $segments
            ->map(fn (TranscriptionSegment $segment) => $segment->getDisplayText())
            ->filter(fn ($text) => $text !== '')
            ->implode(' ');
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
