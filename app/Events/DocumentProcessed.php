<?php

namespace App\Events;

use App\Models\Document;
use App\Models\ProcessingRun;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DocumentProcessed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ProcessingRun $run,
        public Document $document,
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
            'document_id' => $this->document->id,
            'document_name' => $this->document->name,
            'status' => $this->document->status,
            'clean_rate' => $this->document->clean_rate,
            'progress' => $this->run->progress,
            'completed' => $this->run->completed,
            'failed' => $this->run->failed,
            'total' => $this->run->total,
        ];
    }
}
