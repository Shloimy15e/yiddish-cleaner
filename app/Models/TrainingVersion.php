<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrainingVersion extends Model
{
    protected $fillable = [
        'user_id',
        'version',
        'name',
        'description',
        'criteria',
        'document_count',
        'word_count',
        'total_audio_hours',
        'exported_at',
        'export_format',
        'export_path',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'criteria' => 'array',
            'document_count' => 'integer',
            'word_count' => 'integer',
            'total_audio_hours' => 'float',
            'exported_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function audioSamples(): BelongsToMany
    {
        return $this->belongsToMany(AudioSample::class, 'audio_sample_training_version')
            ->withTimestamps();
    }

    public function transcriptions(): HasMany
    {
        return $this->hasMany(Transcription::class);
    }

    public function activate(): void
    {
        // Deactivate other versions for this user
        static::where('user_id', $this->user_id)
            ->where('id', '!=', $this->id)
            ->update(['is_active' => false]);

        $this->update(['is_active' => true]);
    }

    public function updateCounts(): void
    {
        $this->update([
            'document_count' => $this->audioSamples()->count(),
            'word_count' => $this->audioSamples()->sum('metrics->word_count'),
            'total_audio_hours' => $this->audioSamples()->sum('audio_duration_seconds') / 3600,
        ]);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
