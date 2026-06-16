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

    // 🚨 LIGA O MODO DETETIVE:
        $this->withoutExceptionHandling(); 

        \Illuminate\Support\Facades\Mail::fake();
        \Illuminate\Support\Facades\Notification::fake();
        $payload = [
            'name' => 'Jetro Mobilha',
            'email' => 'jetro@email.com',
            'phone' => '923456789', // Agora podemos testar também o telefone
            'password' => 'senha123',
            'password_confirmation' => 'senha123',
        ];

        $response = $this->postJson('/api/register', $payload);

        // 2. Esperamos que crie (201) e devolva a mensagem de sucesso e os dados do user (já não devolve token)
        $response->assertStatus(201)
                 ->assertJsonStructure(['message', 'user']);

        // 3. Verificamos se guardou na base de dados
        $this->assertDatabaseHas('users', [
            'email' => 'jetro@email.com',
            'phone' => '923456789'
        ]);
    }

    public function test_user_can_login()
    {
        // 1. Criamos um utilizador na BD de teste
        $user = \App\Models\User::factory()->create([
            'email' => 'jetro@email.com',
            'password' => \Illuminate\Support\Facades\Hash::make('senha123')
        ]);

        // 2. Tentamos fazer login
       // 1. Agora enviamos 'login_id' em vez de 'email'
        $response = $this->postJson('/api/login', [
            'login_id' => 'jetro@email.com', 
            'password' => 'senha123',
        ]);

        // 3. Esperamos sucesso (200) e um token
        // 2. Esperamos sucesso (200) e a estrutura correta do token do Sanctum
        $response->assertStatus(200)
            ->assertJsonStructure(['access_token', 'token_type']);
            
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

    public function test_login_blocks_after_too_many_attempts()
    {
        // 1. Simular um hacker a tentar fazer login com a password errada 5 vezes seguidas
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', [
                'email' => 'estudante@email.com',
                'password' => 'senha_errada',
            ]);
        }

        // 2. A 6ª tentativa: O Escudo deve ser ativado!
        $response = $this->postJson('/api/login', [
            'email' => 'estudante@email.com',
            'password' => 'outra_senha_errada',
        ]);

        // 3. Esperamos um Erro 429 (Too Many Requests - Demasiados Pedidos)
        $response->assertStatus(429);
    }

    public function test_user_can_request_password_reset_code()
    {
        // 1. Impede o Laravel de tentar ligar à internet para enviar o e-mail real
        \Illuminate\Support\Facades\Mail::fake();

        $user = \App\Models\User::factory()->create([
            'email' => 'jetro@email.com'
        ]);

        // 2. Faz o pedido à API
        $response = $this->postJson('/api/forgot-password', [
            'email' => 'jetro@email.com'
        ]);

        // 3. Verifica se a resposta foi 200 OK
        $response->assertStatus(200)
                 ->assertJson(['message' => 'Enviámos um código de 6 dígitos para o teu e-mail.']);

        // 4. Verifica se o código foi gravado na base de dados
        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => 'jetro@email.com'
        ]);
    }

    public function test_user_can_reset_password_with_valid_code()
    {
        $user = \App\Models\User::factory()->create([
            'email' => 'jetro@email.com',
            'password' => \Illuminate\Support\Facades\Hash::make('senha_antiga')
        ]);

        // 1. Injeta um código falso diretamente na base de dados (como se tivesse sido pedido)
        \Illuminate\Support\Facades\DB::table('password_reset_tokens')->insert([
            'email' => 'jetro@email.com',
            'token' => '123456',
            'created_at' => now()
        ]);

        // 2. Faz o pedido à API com o código correto e a nova senha
        $response = $this->postJson('/api/reset-password', [
            'email' => 'jetro@email.com',
            'code' => '123456',
            'password' => 'nova_senha_secreta',
            'password_confirmation' => 'nova_senha_secreta'
        ]);

        // 3. Verifica se deu sucesso
        $response->assertStatus(200);

        // 4. Verifica se o código foi destruído da base de dados (segurança)
        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => 'jetro@email.com'
        ]);

        // 5. Verifica se a senha do utilizador realmente mudou para a nova
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('nova_senha_secreta', $user->fresh()->password));
    }
}
