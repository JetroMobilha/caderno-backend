<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

// A interface ShouldBroadcastNow faz com que o evento seja enviado IMEDIATAMENTE (zero delay)
class WebRtcSignalReceived implements ShouldBroadcastNow 
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $notebookId;
    public $data;

    public function __construct($notebookId, $data)
    {
        $this->notebookId = $notebookId;
        $this->data = $data;
    }

    // Cria um "Canal Privado" (Sala) só para quem está a ver este caderno
    public function broadcastOn(): array
    {
        return [
            new PresenceChannel('notebook.' . $this->notebookId),
        ];
    }

    // O nome do evento que o Flutter vai escutar
    public function broadcastAs(): string
    {
        return 'webrtc.signal';
    }
}
