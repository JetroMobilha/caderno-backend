<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use App\Models\User;

class HandwritingSynthesisApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Garante que o diretório e o ficheiro de alfabeto existem para os testes
        $dir = base_path('scripts/handwriting-engine');
        if (!File::exists($dir)) {
            File::makeDirectory($dir, 0755, true);
        }
        File::put(
            $dir . '/alfabeto.json',
            json_encode([
                "o" => ["width" => 30, "strokes" => [["points" => [["dx" => 15, "dy" => 15]]]]],
                "l" => ["width" => 20, "strokes" => [["points" => [["dx" => 10, "dy" => 30]]]]],
                "a" => ["width" => 30, "strokes" => [["points" => [["dx" => 15, "dy" => 15]]]]]
            ], JSON_PRETTY_PRINT)
        );
    }

    /**
     * @test
     * Teste para uma requisição de síntese bem-sucedida.
     */
    public function it_returns_a_successful_response_for_a_valid_request(): void
    {
        $user = User::factory()->create();
        $textToSynthesize = 'ola';

        $response = $this->actingAs($user)->postJson('/api/handwriting/synthesize', [
            'text' => $textToSynthesize,
        ]);

        $response->assertStatus(200);
        $response->assertJsonIsArray();
    }

    /**
     * @test
     * Teste para erro de validação quando o campo 'text' está em falta.
     */
    public function it_returns_a_validation_error_if_text_is_missing(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/handwriting/synthesize', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['text']);
    }

    /**
     * @test
     * Teste para o caso de um caractere não existir no alfabeto.
     */
    public function it_returns_an_error_if_a_character_is_not_in_the_alphabet(): void
    {
        $user = User::factory()->create();
        $textToSynthesize = 'olax'; // 'x' não existe no nosso alfabeto de teste

        $response = $this->actingAs($user)->postJson('/api/handwriting/synthesize', [
            'text' => $textToSynthesize,
        ]);

        $response->assertStatus(500);
        $response->assertJson(['error' => 'Não foi possível gerar a escrita manual.']);
    }
}