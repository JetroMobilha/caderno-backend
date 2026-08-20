<?php

namespace App\Events;

use App\Models\CollaborativeSession;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class VoicePolicyUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public CollaborativeSession $session,
        public ?string $targetUserId = null,
        public ?bool $canSpeak = null
    ) {}

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PresenceChannel('notebook.' . $this->session->notebook_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'client-voice-policy-updated';
    }

    public function broadcastWith(): array
    {
        return [
            'notebook_id' => $this->session->notebook_id,
            'voice_mode'  => $this->session->voice_mode,
            'target_id'   => $this->targetUserId,
            'can_speak'   => $this->canSpeak,
            'updated_at'  => now()->timestamp * 1000,
        ];
    }
}
