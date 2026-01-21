<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Transcription extends Model implements HasMedia
{
    use InteractsWithMedia;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'audio_sample_id',
        'training_version_id',
        'model_name',
        'model_version',
        'source',
        'status',
        'hypothesis_text',
        'hypothesis_hash',
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

    public function audioSample(): BelongsTo
    {
        return $this->belongsTo(AudioSample::class);
    }

    public function trainingVersion(): BelongsTo
    {
        return $this->belongsTo(TrainingVersion::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('hypothesis_transcript')
            ->singleFile()
            ->acceptsMimeTypes(['text/plain']);
    }

    public function getTotalErrorsAttribute(): int
    {
        return ($this->substitutions ?? 0) + ($this->insertions ?? 0) + ($this->deletions ?? 0);
    }

    public function scopeForModel($query, string $modelName)
    {
        return $query->where('model_name', $modelName);
    }
}
