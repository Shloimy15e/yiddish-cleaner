<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TranscriptionSegment extends Model
{
    use HasFactory;

    protected $fillable = [
        'transcription_id',
        'segment_index',
        'text',
        'corrected_text',
        'start_time',
        'end_time',
        'confidence',
        'words_json',
        'corrected_by',
        'corrected_at',
    ];

    protected function casts(): array
    {
        return [
            'segment_index' => 'integer',
            'start_time' => 'decimal:3',
            'end_time' => 'decimal:3',
            'confidence' => 'decimal:4',
            'words_json' => 'array',
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
     * Scope to get segments below a confidence threshold.
     */
    public function scopeBelowConfidence($query, float $threshold)
    {
        return $query->where(function ($q) use ($threshold) {
            $q->whereNotNull('confidence')
                ->where('confidence', '<=', $threshold);
        });
    }

    /**
     * Scope to get segments that have been corrected.
     */
    public function scopeCorrected($query)
    {
        return $query->whereNotNull('corrected_text');
    }

    /**
     * Scope to get segments that need review (low confidence and not corrected).
     */
    public function scopeNeedsReview($query, float $threshold = 0.7)
    {
        return $query->belowConfidence($threshold)
            ->whereNull('corrected_text');
    }

    // ==================== Helper Methods ====================

    /**
     * Check if this segment has been corrected.
     */
    public function isCorrected(): bool
    {
        return $this->corrected_text !== null;
    }

    /**
     * Get the display text (corrected if available, otherwise original).
     */
    public function getDisplayText(): string
    {
        return $this->corrected_text ?? $this->text;
    }

    /**
     * Get the segment duration in seconds.
     */
    public function getDuration(): float
    {
        return (float) $this->end_time - (float) $this->start_time;
    }

    /**
     * Get approximate word count for this segment.
     */
    public function getWordCount(): int
    {
        return str_word_count($this->getDisplayText());
    }

    /**
     * Check if this segment has embedded word-level timing.
     */
    public function hasWords(): bool
    {
        return ! empty($this->words_json);
    }

    /**
     * Get embedded words as array.
     *
     * @return array<array{word: string, start: float, end: float}>
     */
    public function getWords(): array
    {
        return $this->words_json ?? [];
    }

    /**
     * Apply a correction to this segment.
     */
    public function applyCorrection(string $correctedText, ?int $userId = null): void
    {
        $this->update([
            'corrected_text' => $correctedText,
            'corrected_by' => $userId,
            'corrected_at' => now(),
        ]);
    }

    /**
     * Clear any correction on this segment.
     */
    public function clearCorrection(): void
    {
        $this->update([
            'corrected_text' => null,
            'corrected_by' => null,
            'corrected_at' => null,
        ]);
    }
}
