<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Models\Page;

class PageUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Page $page;

    /**
     * Create a new event instance.
     */
    public function __construct(Page $page)
    {
        $this->page = $page;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PresenceChannel('notebook.' . $this->page->notebook_id),
        ];
    }

    /**
     * The name of the event to be broadcast.
     */
    public function broadcastAs(): string
    {
        return 'page.updated';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        // Return the entire page model, which will be serialized to JSON.
        // This ensures high-precision timestamps (updated_at_ms) are included.
        return [
            'page' => $this->page->toArray(),
        ];
    }
}
