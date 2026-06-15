<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use App\Events\WebRtcSignalReceived;
use App\Models\User;
use App\Models\Subject;
use Tests\TestCase;

class WebRtcApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_send_webrtc_signal()
    {
        // Intercepta os eventos para não tentar usar a internet durante o teste
        Event::fake(); 

        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $subject = Subject::create(['user_id' => $user->id, 'name' => 'Física']);
        $notebook = $subject->notebooks()->create(['title' => 'Trabalho de Grupo']);

        $response = $this->postJson("/api/notebooks/{$notebook->id}/webrtc/signal", [
            'type' => 'offer',
            'payload' => 'codigo_secreto_sdp_do_flutter'
        ]);

        $response->assertStatus(200);

        // Verifica se o Laravel realmente tentou transmitir o evento
        Event::assertDispatched(WebRtcSignalReceived::class, function ($event) use ($notebook) {
            return $event->notebookId === $notebook->id && $event->data['type'] === 'offer';
        });
    }
}