<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\User;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;
    /**
     * A basic feature test example.
     */
    public function test_user_can_register()
    {
        $payload = [
            'name' => 'Jetro Mobilha',
            'email' => 'jetro@email.com',
            'password' => 'senha_super_segura',
        ];

        $response = $this->postJson('/api/register', $payload);

        // Esperamos que crie (201) e devolva um token
        $response->assertStatus(201)
                 ->assertJsonStructure(['user', 'token']);

        // Verificamos se guardou na base de dados
        $this->assertDatabaseHas('users', [
            'email' => 'jetro@email.com'
        ]);
    }

    public function test_user_can_login()
    {
        // 1. Criamos um utilizador na BD de teste
        $user = User::factory()->create([
            'email' => 'login@email.com',
            'password' => bcrypt('senha123')
        ]);

        // 2. Tentamos fazer login
        $response = $this->postJson('/api/login', [
            'email' => 'login@email.com',
            'password' => 'senha123',
        ]);

        // 3. Esperamos sucesso (200) e um token
        $response->assertStatus(200)
                 ->assertJsonStructure(['token']);
    }

    public function test_user_can_logout()
    {
        // 1. Criamos um utilizador
        $user = User::factory()->create();
        
        // 2. Criamos um Token REAL para ele
        $token = $user->createToken('test_token')->plainTextToken;

        // 3. Fazemos o pedido passando o token no cabeçalho (Headers)
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/logout');

        // 4. Esperamos sucesso
        $response->assertStatus(200)
                 ->assertJsonFragment(['message' => 'Sessão terminada com sucesso']);
    }
}
