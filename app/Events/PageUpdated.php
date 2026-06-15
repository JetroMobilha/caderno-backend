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

    public $page;

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
        // Criamos uma "sala" específica para o caderno
        return [
            new PresenceChannel('notebook.' . $this->page->notebook_id),
        ];
    }

    /**
     * Nome do evento que o Flutter vai escutar.
     * Se não definires isto, o Laravel usa o nome da classe.
     */
    public function broadcastAs(): string
    {
        return 'page.updated';
    }
}
