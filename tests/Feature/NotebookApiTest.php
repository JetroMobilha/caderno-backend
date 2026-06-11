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
            'cover_type' => 'capa_azul_ondas'
        ];

        // 4. Fazemos o pedido POST para a rota (que vamos criar a seguir)
        // Repara que o ID da disciplina vai no URL
        $response = $this->postJson("/api/subjects/{$subject->id}/notebooks", $payload);

        // 5. Esperamos que crie com sucesso (201)
        $response->assertStatus(201)
                 ->assertJsonFragment(['title' => 'Mecânica Quântica']);

        // 6. Verificamos se gravou na Base de Dados com o subject_id correto
        $this->assertDatabaseHas('notebooks', [
            'title' => 'Mecânica Quântica',
            'subject_id' => $subject->id
        ]);
    }
}
