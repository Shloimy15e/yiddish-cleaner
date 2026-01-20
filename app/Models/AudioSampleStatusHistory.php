<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AudioSampleStatusHistory extends Model
{
    protected $table = 'audio_sample_status_history';

    protected $fillable = [
        'audio_sample_id',
        'user_id',
        'from_status',
        'to_status',
        'action',
        'notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    // Action constants
    public const ACTION_CREATED = 'created';
    public const ACTION_CLEANING_STARTED = 'cleaning_started';
    public const ACTION_CLEANED = 'cleaned';
    public const ACTION_EDITED = 'edited';
    public const ACTION_VALIDATED = 'validated';
    public const ACTION_UNVALIDATED = 'unvalidated';
    public const ACTION_TRANSCRIPT_REPLACED = 'transcript_replaced';
    public const ACTION_TRANSCRIPT_UPLOADED = 'transcript_uploaded';

    public function audioSample(): BelongsTo
    {
        return $this->belongsTo(AudioSample::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Create a history entry for an audio sample.
     */
    public static function log(
        AudioSample $audioSample,
        string $action,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        ?int $userId = null,
        ?string $notes = null,
        ?array $metadata = null
    ): self {
        return self::create([
            'audio_sample_id' => $audioSample->id,
            'user_id' => $userId ?? (auth()->check() ? auth()->user()->id : null),
            'from_status' => $fromStatus ?? $audioSample->status,
            'to_status' => $toStatus ?? $audioSample->status,
            'action' => $action,
            'notes' => $notes,
            'metadata' => $metadata,
        ]);
    }
}
