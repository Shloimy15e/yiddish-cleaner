<?php

namespace App\Events;

use App\Models\ProcessingRun;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BatchCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ProcessingRun $run,
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
            'status' => $this->run->status,
            'completed' => $this->run->completed,
            'failed' => $this->run->failed,
            'total' => $this->run->total,
        ];
    }
}
