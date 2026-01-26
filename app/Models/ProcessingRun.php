<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProcessingRun extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id',
        'batch_id',
        'preset',
        'mode',
        'llm_provider',
        'llm_model',
        'source_type',
        'source_url',
        'total',
        'completed',
        'failed',
        'status',
        'error_message',
        'options',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'integer',
            'completed' => 'integer',
            'failed' => 'integer',
            'options' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function audioSamples(): HasMany
    {
        return $this->hasMany(AudioSample::class);
    }

    public function incrementCompleted(): void
    {
        $this->increment('completed');
        $this->checkCompletion();
    }

    public function incrementFailed(): void
    {
        $this->increment('failed');
        $this->checkCompletion();
    }

    protected function checkCompletion(): void
    {
        if (($this->completed + $this->failed) >= $this->total) {
            $updates = [
                'status' => $this->failed > 0 ? 'completed_with_errors' : 'completed',
            ];

            if ($this->failed > 0) {
                $updates['error_message'] = "{$this->failed} document(s) failed to process. View document details for specific errors.";
            }

            $this->update($updates);
        }
    }

    public function getProgressAttribute(): float
    {
        if ($this->total === 0) {
            return 0;
        }

        return round(($this->completed + $this->failed) / $this->total * 100, 1);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', 'processing');
    }
}
