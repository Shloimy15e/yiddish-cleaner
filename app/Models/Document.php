<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    protected $fillable = [
        'processing_run_id',
        'name',
        'source_url',
        'original_hash',
        'cleaned_hash',
        'original_text',
        'cleaned_text',
        'audio_link',
        'audio_length',
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
            'audio_length' => 'float',
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
        return $this->belongsToMany(TrainingVersion::class, 'training_document')
            ->withTimestamps();
    }

    public function benchmarkResults(): HasMany
    {
        return $this->hasMany(BenchmarkResult::class);
    }

    public function isValidated(): bool
    {
        return $this->validated_at !== null;
    }

    public function validate(?string $validatedBy = null, ?string $notes = null): void
    {
        $this->update([
            'validated_at' => now(),
            'validated_by' => $validatedBy,
            'review_notes' => $notes,
        ]);
    }

    public function scopeValidated($query)
    {
        return $query->whereNotNull('validated_at');
    }

    public function scopePendingValidation($query)
    {
        return $query->whereNull('validated_at')
            ->where('status', 'completed');
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
