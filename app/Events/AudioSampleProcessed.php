<?php

namespace App\Events;

use App\Models\AudioSample;
use App\Models\ProcessingRun;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AudioSampleProcessed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ProcessingRun $run,
        public AudioSample $audioSample,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("runs.{$this->run->id}"),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'run_id' => $this->run->id,
            'audio_sample_id' => $this->audioSample->id,
            'audio_sample_name' => $this->audioSample->name,
            'status' => $this->audioSample->status,
            'clean_rate' => $this->audioSample->clean_rate,
            'progress' => $this->run->progress,
            'completed' => $this->run->completed,
            'failed' => $this->run->failed,
            'total' => $this->run->total,
        ];
    }
}
