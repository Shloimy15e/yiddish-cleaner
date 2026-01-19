<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BenchmarkResult extends Model
{
    protected $fillable = [
        'document_id',
        'training_version_id',
        'model_name',
        'model_version',
        'transcribed_text',
        'transcribed_hash',
        'wer',
        'cer',
        'substitutions',
        'insertions',
        'deletions',
        'reference_words',
        'errors',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'wer' => 'float',
            'cer' => 'float',
            'substitutions' => 'integer',
            'insertions' => 'integer',
            'deletions' => 'integer',
            'reference_words' => 'integer',
            'errors' => 'array',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function trainingVersion(): BelongsTo
    {
        return $this->belongsTo(TrainingVersion::class);
    }

    public function getTotalErrorsAttribute(): int
    {
        return $this->substitutions + $this->insertions + $this->deletions;
    }

    public function scopeForModel($query, string $modelName)
    {
        return $query->where('model_name', $modelName);
    }
}
