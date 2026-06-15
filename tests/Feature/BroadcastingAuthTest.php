<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Subject;
use App\Models\Notebook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BroadcastingAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_authorize_presence_channel()
    {
        $user = User::factory()->create();
        $subject = Subject::create(['user_id' => $user->id, 'name' => 'Teste']);
        $notebook = $subject->notebooks()->create(['title' => 'Caderno Realtime']);

        // O Laravel usa a rota /broadcasting/auth para validar canais privados/presença
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/broadcasting/auth', [
                'channel_name' => 'presence-notebook.' . $notebook->id,
                'socket_id' => '12345.67890'
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('channel_data.user_info.name', $user->name);
    }

    public function test_collaborator_can_authorize_presence_channel()
    {
        $owner = User::factory()->create();
        $friend = User::factory()->create();
        $subject = Subject::create(['user_id' => $owner->id, 'name' => 'Partilha']);
        $notebook = $subject->notebooks()->create(['title' => 'Caderno Partilhado']);

        $notebook->sharedUsers()->attach($friend->id, ['role' => 'editor']);

        $response = $this->actingAs($friend, 'sanctum')
            ->postJson('/api/broadcasting/auth', [
                'channel_name' => 'presence-notebook.' . $notebook->id,
                'socket_id' => '12345.67890'
            ]);

        $response->assertStatus(200);
    }

    public function test_unauthorized_user_cannot_join_channel()
    {
        $user = User::factory()->create();
        $notebook = Notebook::factory()->create(); // Caderno de outro utilizador

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/broadcasting/auth', [
                'channel_name' => 'presence-notebook.' . $notebook->id,
                'socket_id' => '12345.67890'
            ]);

        $response->assertStatus(403);
    }
}
