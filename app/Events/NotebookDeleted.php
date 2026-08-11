<?php

namespace App\Events;

use App\Models\Notebook;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotebookDeleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(public Notebook $notebook) {}

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PresenceChannel('notebook.' . $this->notebook->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'notebook.deleted';
    }

    public function broadcastWith(): array
    {
        return [
            'notebook_id' => $this->notebook->id,
            'deleted_at'  => now()->toISOString(),
        ];
    }
}
