<?php

namespace App\Events;

use App\Models\Notebook;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotebookStructureUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Notebook $notebook,
        public array $structure,
        public ?string $alternativeTitle = null,
        public ?string $sharingType = 'full',
        public ?array $authorizedPageIds = null
    ) {}

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
        return 'notebook.structure.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'notebook_id' => $this->notebook->id,
            'structure'   => $this->structure,
            'alternative_title' => $this->alternativeTitle,
            'sharing_type' => $this->sharingType,
            'authorized_page_ids' => $this->authorizedPageIds,
            'updated_at'  => now()->timestamp * 1000,
        ];
    }
}
