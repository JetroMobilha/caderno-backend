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
        return 'PageUpdated';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        // 🚀 SINALIZAÇÃO LEVE: Enviamos apenas o essencial para o App saber o que atualizar.
        // Evitamos enviar stroke_data/image_data para não sobrecarregar o Reverb.
        return [
            'notebook_id' => $this->page->notebook_id,
            'page_number' => $this->page->page_number,
            'client_id'   => $this->page->client_id,
            'updated_at_ms' => $this->page->updated_at_ms,
        ];
    }
}
