<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TranscriptionWord extends Model
{
    use HasFactory;
    protected $fillable = [
        'transcription_id',
        'word_index',
        'word',
        'start_time',
        'end_time',
        'confidence',
        'corrected_word',
        'is_deleted',
        'is_inserted',
        'corrected_by',
        'corrected_at',
    ];

    protected function casts(): array
    {
        return [
            'word_index' => 'decimal:4',
            'start_time' => 'decimal:3',
            'end_time' => 'decimal:3',
            'confidence' => 'decimal:3',
            'is_deleted' => 'boolean',
            'is_inserted' => 'boolean',
            'corrected_at' => 'datetime',
        ];
    }

    // ==================== Relationships ====================

    public function transcription(): BelongsTo
    {
        return $this->belongsTo(Transcription::class);
    }

    public function corrector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }

    // ==================== Scopes ====================

    /**
     * Scope to get words below a confidence threshold.
     */
    public function scopeBelowConfidence($query, float $threshold)
    {
        return $query->where(function ($q) use ($threshold) {
            $q->whereNotNull('confidence')
              ->where('confidence', '<=', $threshold);
        });
    }

    /**
     * Scope to get words that have been corrected.
     */
    public function scopeCorrected($query)
    {
        return $query->where(function ($q) {
            $q->whereNotNull('corrected_word')
              ->orWhere('is_deleted', true)
              ->orWhere('is_inserted', true);
        });
    }

    /**
     * Scope to get active words (not deleted).
     */
    public function scopeActive($query)
    {
        return $query->where('is_deleted', false);
    }

    /**
     * Scope to get original ASR words (not user-inserted).
     */
    public function scopeOriginal($query)
    {
        return $query->where('is_inserted', false);
    }

    // ==================== Helper Methods ====================

    /**
     * Check if this word has been corrected in any way.
     */
    public function isCorrected(): bool
    {
        return $this->corrected_word !== null || $this->is_deleted || $this->is_inserted;
    }

    /**
     * Get the display word (corrected if available, otherwise original).
     */
    public function getDisplayWord(): string
    {
        if ($this->is_deleted) {
            return '';
        }

        return $this->corrected_word ?? $this->word;
    }

    /**
     * Get the word duration in seconds.
     */
    public function getDuration(): float
    {
        return (float) $this->end_time - (float) $this->start_time;
    }

    /**
     * Apply a correction to this word.
     */
    public function applyCorrection(?string $correctedWord, ?int $userId = null): void
    {
        $this->update([
            'corrected_word' => $correctedWord,
            'is_deleted' => false,
            'corrected_by' => $userId,
            'corrected_at' => now(),
        ]);
    }

    /**
     * Mark this word as deleted.
     */
    public function markDeleted(?int $userId = null): void
    {
        $this->update([
            'is_deleted' => true,
            'corrected_by' => $userId,
            'corrected_at' => now(),
        ]);
    }

    /**
     * Clear any correction on this word.
     */
    public function clearCorrection(): void
    {
        // Can't clear correction on inserted words - delete them instead
        if ($this->is_inserted) {
            $this->delete();
            return;
        }

        $this->update([
            'corrected_word' => null,
            'is_deleted' => false,
            'corrected_by' => null,
            'corrected_at' => null,
        ]);
    }

    /**
     * Create an inserted word between two existing words.
     */
    public static function insertBetween(
        Transcription $transcription,
        string $word,
        TranscriptionWord $before,
        ?TranscriptionWord $after = null,
        ?int $userId = null
    ): self {
        // Calculate word index between the two words
        $beforeIndex = (float) $before->word_index;
        $afterIndex = $after ? (float) $after->word_index : $beforeIndex + 1;
        $newIndex = ($beforeIndex + $afterIndex) / 2;

        // Calculate timing - take a sliver from adjacent words
        $startTime = (float) $before->end_time;
        $endTime = $after ? (float) $after->start_time : $startTime + 0.2;
        
        // If there's no gap, create a small one in the middle
        if ($endTime <= $startTime) {
            $midPoint = ($startTime + ($after ? (float) $after->end_time : $startTime + 0.4)) / 2;
            $startTime = $midPoint - 0.1;
            $endTime = $midPoint + 0.1;
        }

        return self::create([
            'transcription_id' => $transcription->id,
            'word_index' => $newIndex,
            'word' => $word,
            'start_time' => max(0, $startTime),
            'end_time' => $endTime,
            'confidence' => null,
            'is_inserted' => true,
            'corrected_by' => $userId,
            'corrected_at' => now(),
        ]);
    }
}
