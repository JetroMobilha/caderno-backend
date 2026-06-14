<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Subject;
use App\Models\Notebook;

class ShareNotebookApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_share_notebook_with_another_user()
    {
        // Desliga o escudo para vermos o erro real!
        $this->withoutExceptionHandling();
        
        // 1. Criar o dono do caderno (Jetro)
        $owner = User::factory()->create();
        $this->actingAs($owner, 'sanctum');

        // 2. Criar a disciplina e o caderno do Jetro
        $subject = Subject::create(['user_id' => $owner->id, 'name' => 'História', 'color' => '#FFF']);
        $notebook = $subject->notebooks()->create(['title' => 'Resumos', 'cover_type' => 'basic']);

        // 3. Criar o colega com quem vamos partilhar (Carlos)
        $friend = User::factory()->create(['email' => 'carlos@email.com']);

        // 4. Os dados que o Flutter vai enviar para partilhar
        $payload = [
            'email' => 'carlos@email.com',
            'role' => 'viewer' // Pode ser 'viewer' ou 'editor'
        ];

        // 5. O pedido POST para a rota de partilha
        $response = $this->postJson("/api/notebooks/{$notebook->id}/share", $payload);

        // 6. Esperamos sucesso
        $response->assertStatus(200)
                 ->assertJsonFragment(['message' => 'Caderno partilhado com sucesso!']);

        // 7. A Prova Final: O Laravel tem de ter gravado isto na tabela pivô
        $this->assertDatabaseHas('notebook_user', [
            'notebook_id' => $notebook->id,
            'user_id' => $friend->id,
            'role' => 'viewer'
        ]);
    }
}