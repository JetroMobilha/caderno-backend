<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Subject;
use App\Models\Notebook;

class PageApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_save_canvas_strokes_to_a_page()
    {
        // 1. Criar ambiente (User -> Subject -> Notebook)
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $subject = Subject::create(['user_id' => $user->id, 'name' => 'Design', 'color' => '#000000']);
        $notebook = $subject->notebooks()->create(['title' => 'Esboços', 'cover_type' => 'leather']);

        // 2. Simular os dados complexos de desenho (JSON) que o Flutter enviaria
        $mockStrokeData = [
            [
                'color' => '#FF0000',
                'thickness' => 4.5,
                'points' => [
                    ['x' => 10.5, 'y' => 20.1],
                    ['x' => 12.3, 'y' => 25.8],
                    ['x' => 15.0, 'y' => 30.2],
                ]
            ],
            [
                'color' => '#000000',
                'thickness' => 2.0,
                'points' => [
                    ['x' => 100.0, 'y' => 150.0],
                    ['x' => 105.2, 'y' => 155.1],
                ]
            ]
        ];

        $payload = [
            'page_number' => 1,
            'stroke_data' => $mockStrokeData
        ];

        // 3. Fazer o pedido POST para salvar os traços na página do caderno
        $response = $this->postJson("/api/notebooks/{$notebook->id}/pages", $payload);

        // 4. Verificações
        $response->assertStatus(201)
                 ->assertJsonFragment(['page_number' => 1]);

        // Garantir que o Laravel guardou o JSON corretamente na BD
        $this->assertDatabaseHas('pages', [
            'notebook_id' => $notebook->id,
            'page_number' => 1
        ]);
    }

    public function test_user_can_fetch_pages_with_pagination()
    {
        // 1. Criar ambiente (Utilizador, Disciplina, Caderno)
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $subject = \App\Models\Subject::create(['user_id' => $user->id, 'name' => 'Artes', 'color' => '#000']);
        $notebook = $subject->notebooks()->create(['title' => 'Retratos', 'cover_type' => 'basic']);

        // 2. Simular a criação de 25 páginas no caderno
        for ($i = 1; $i <= 25; $i++) {
            $notebook->pages()->create([
                'page_number' => $i,
                'stroke_data' => []
            ]);
        }

        // 3. Fazer o pedido GET para listar as páginas
        $response = $this->getJson("/api/notebooks/{$notebook->id}/pages");

        // 4. Esperamos sucesso (200)
        // E esperamos que o Laravel só nos devolva 20 páginas no array 'data', 
        // embora saiba que o total são 25!
        $response->assertStatus(200)
                 ->assertJsonCount(20, 'data') 
                 ->assertJsonFragment(['total' => 25]); 
    }
}