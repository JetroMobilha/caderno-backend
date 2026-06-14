<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\User;
use App\Models\Subject;

class NotebookApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_a_notebook_inside_a_subject()
    {
        // 1. Criar o utilizador e autenticar
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        // 2. Criar uma disciplina (Subject) que pertence a este utilizador
        $subject = Subject::create([
            'user_id' => $user->id,
            'name' => 'Física',
            'color' => '#0000FF'
        ]);

        // 3. Os dados do caderno que o Flutter vai enviar
        $payload = [
            'title' => 'Mecânica Quântica',
            'cover_type' => 'capa_azul_ondas',
            'color' => '#FF0000',               
            'cover_image' => 'url/imagem.png',
            'line_type' => 'grid'    
        ];

        // 4. Fazemos o pedido POST para a rota (que vamos criar a seguir)
        // Repara que o ID da disciplina vai no URL
        $response = $this->postJson("/api/subjects/{$subject->id}/notebooks", $payload);

        // 5. Esperamos que crie com sucesso (201)
        $response->assertStatus(201)
                 ->assertJsonFragment(['title' => 'Mecânica Quântica'
                 ,'color' => '#FF0000'
                 ,'line_type' => 'grid']);

        // 6. Verificamos se gravou na Base de Dados com o subject_id correto
        $this->assertDatabaseHas('notebooks', [
            'title' => 'Mecânica Quântica',
            'subject_id' => $subject->id
        ]);
    }

    public function test_user_can_soft_delete_a_notebook()
    {
        // 1. Criar utilizador, disciplina e caderno
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $subject = \App\Models\Subject::create(['user_id' => $user->id, 'name' => 'Física', 'color' => '#FFF']);
        $notebook = $subject->notebooks()->create(['title' => 'Mecânica', 'cover_type' => 'basic']);

        // 2. Fazer o pedido de DELETE
        $response = $this->deleteJson("/api/subjects/{$subject->id}/notebooks/{$notebook->id}");

        // 3. Esperar que devolva 200 OK
        $response->assertStatus(200)
                 ->assertJsonFragment(['message' => 'Caderno movido para a lixeira.']);

        // 4. A Mágica do Soft Delete: O caderno AINDA tem de estar na BD, 
        // mas a coluna 'deleted_at' já não pode ser nula!
        $this->assertSoftDeleted('notebooks', [
            'id' => $notebook->id
        ]);
    }
}
