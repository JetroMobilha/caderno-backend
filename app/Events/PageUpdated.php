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

class PageUpdated implements ShouldBroadcastNow // ShouldBroadcastNow para latência mínima
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $notebookId;
    public $pageId;
    public $pageNumber;
    public $newStrokes; // Esta propriedade conterá apenas os traços que foram adicionados/atualizados

    /**
     * Create a new event instance.
     */
    public function __construct(int $notebookId, int $pageId, int $pageNumber, array $newStrokes)
    {
        $this->notebookId = $notebookId;
        $this->pageId = $pageId;
        $this->pageNumber = $pageNumber;
        $this->newStrokes = $newStrokes;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PresenceChannel('notebook.' . $this->notebookId),
        ];
    }

    /**
     * Nome do evento que o Flutter vai escutar.
     */
    public function broadcastAs(): string
    {
        return 'page.strokes.added'; // Nome mais específico para o evento
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'page_id' => $this->pageId,
            'page_number' => $this->pageNumber,
            'strokes' => $this->newStrokes,
        ];
    }
}
