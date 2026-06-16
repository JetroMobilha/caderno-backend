<?php
namespace Tests\Feature;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_activates_pro_plan_with_valid_signature()
    {
        // Configura uma chave fictícia para o teste
        config(['app.env' => 'testing']);
        $apiKey = 'chave_secreta_teste';
        config(['services.proxypay.key' => $apiKey]); 
        // Nota: Garante que no teu controlador ou .env a chave bate certo.
        
        $user = User::factory()->create(['pro_expires_at' => null]);
        
        $payment = Payment::create([
            'user_id' => $user->id,
            'amount' => 2500.00,
            'reference' => '123456789',
            'status' => 'pending',
        ]);

        $payload = [
            'reference' => [
                'number' => '123456789',
                'custom_fields' => [
                    'payment_id' => (string) $payment->id
                ]
            ]
        ];

        $jsonPayload = json_encode($payload);
        $signature = hash_hmac('sha256', $jsonPayload, $apiKey);

        // Faz o pedido simulando os cabeçalhos da Proxypay
        $response = $this->withHeaders([
            'X-Signature' => $signature,
        ])->postJson('/api/webhooks/payment-confirmation', $payload);

        $response->assertStatus(200);
        
        // Verifica se o pagamento mudou para completed e o user ganhou os 30 dias
        $this->assertEquals('completed', $payment->fresh()->status);
        $this->assertTrue($user->fresh()->isPro());
    }
}