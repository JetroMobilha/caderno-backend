<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\User;

class SubjectApiTest extends TestCase
{

    // Esta linha garante que a base de dados de testes é limpa após cada teste
    use RefreshDatabase;
    public function test_user_can_create_a_subject()
    {
        // 1. Criar um utilizador falso para o teste
        $user = User::factory()->create();

        // 2. Simular que esse utilizador fez login via Sanctum
        $this->actingAs($user, 'sanctum');

        // 3. Os dados que o Flutter enviaria
        $payload = [
            'name' => 'Matemática',
            'color' => '#FF0000',
            'icon' => 'science_icon',
        ];

        // 4. Fazer o pedido POST para a nossa (futura) rota
        $response = $this->postJson('/api/subjects', $payload);

        // 5. O que esperamos de volta?
        // Esperamos um código 201 (Criado com Sucesso)
        $response->assertStatus(201)
                 ->assertJsonFragment(['name' => 'Matemática',
                 'icon' => 'science_icon']);

        // E esperamos que a disciplina esteja realmente guardada na Base de Dados
        $this->assertDatabaseHas('subjects', [
            'name' => 'Matemática',
            'icon' => 'science_icon',
            'user_id' => $user->id
        ]);
    }
}
