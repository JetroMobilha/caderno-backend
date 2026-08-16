<?php

namespace App\Events;

use App\Models\Notebook;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotebookAccessRevoked implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Notebook $notebook, public int $userId) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->userId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'notebook.access_revoked';
    }

    public function broadcastWith(): array
    {
        return [
            'notebook_id' => $this->notebook->id,
            'server_id' => $this->notebook->id,
            'revoked_at' => now()->toISOString(),
        ];
    }
}
