<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SyncRequested implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $userId;

    /**
     * Cria uma nova instância de evento.
     */
    public function __construct($userId)
    {
        $this->userId = $userId;
    }

    /**
     * Define em que canais o evento deve ser transmitido.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        // 🚀 Transmite para o canal privado 'user.{id}'
        return [
            new PrivateChannel('user.' . $this->userId),
        ];
    }

    /**
     * O nome do evento que o Flutter vai escutar (o método .bind())
     */
    public function broadcastAs(): string
    {
        return 'SyncRequested';
    }

    /**
     * Dados opcionais que queiras enviar no payload.
     * Como a nossa app é Offline-First, mandamos apenas um sinal,
     * e o telemóvel encarrega-se de fazer o PULL de forma segura.
     */
    public function broadcastWith(): array
    {
        return [
            'triggered_at' => now()->toIso8601String(),
            'message' => 'Nova atualização detetada. Executa o Pull.'
        ];
    }
}