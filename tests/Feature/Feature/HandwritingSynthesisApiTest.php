<?php

namespace Tests\Feature\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;

class HandwritingSynthesisApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Ensure the alphabet file exists for the tests
        $this->artisan('make:handwriting-alphabet-file')->assertExitCode(0);
    }

    /**
     * @test
     * Teste para uma requisição de síntese bem-sucedida.
     */
    public function it_returns_a_successful_response_for_a_valid_request(): void
    {
        // Cria um utilizador para autenticação
        $user = User::factory()->create();

        // O texto a ser sintetizado. Usamos "ola" porque 'o', 'l' e 'a' existem no alphabet.json
        $textToSynthesize = 'ola';

        // Atua como o utilizador autenticado e faz a chamada à API
        $response = $this->actingAs($user)->postJson('/api/handwriting/synthesize', [
            'text' => $textToSynthesize,
        ]);

        // Verifica se a resposta tem o status 200 (OK)
        $response->assertStatus(200);

        // Verifica se a resposta contém a chave 'stroke_data'
        $response->assertJsonStructure([
            'stroke_data',
        ]);

        // Verifica se 'stroke_data' é um array (pode estar vazio ou não)
        $response->assertJsonIsArray('stroke_data');
    }

    /**
     * @test
     * Teste para erro de validação quando o campo 'text' está em falta.
     */
    public function it_returns_a_validation_error_if_text_is_missing(): void
    {
        // Cria um utilizador para autenticação
        $user = User::factory()->create();

        // Atua como o utilizador autenticado e faz a chamada à API sem o campo 'text'
        $response = $this->actingAs($user)->postJson('/api/handwriting/synthesize', []);

        // Verifica se a resposta tem o status 422 (Unprocessable Entity)
        $response->assertStatus(422);

        // Verifica se a resposta JSON contém o erro de validação para o campo 'text'
        $response->assertJsonValidationErrors(['text']);
    }

    /**
     * @test
     * Teste para o caso de um caractere não existir no alfabeto.
     */
    public function it_returns_an_error_if_a_character_is_not_in_the_alphabet(): void
    {
        // Cria um utilizador para autenticação
        $user = User::factory()->create();

        // 'x' não existe no alphabet.json básico
        $textToSynthesize = 'olax';

        // Atua como o utilizador autenticado e faz a chamada à API
        $response = $this->actingAs($user)->postJson('/api/handwriting/synthesize', [
            'text' => $textToSynthesize,
        ]);

        // Esperamos um erro 500 porque o script Node.js deve falhar
        $response->assertStatus(500);

        // Verifica se a resposta contém uma mensagem de erro
        $response->assertJsonFragment([
            'error' => 'Handwriting synthesis failed.',
        ]);
    }
}
