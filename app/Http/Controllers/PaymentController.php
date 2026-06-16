<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;


class PaymentController extends Controller
{
    public function generateReference(Request $request)
    {
        // 1. Identificar o estudante autenticado
        $user = $request->user();

        // 2. Definir o montante (Ex: Plano Pro custa 2.500 Kz)
        $amount = 2500.00; 

        // 3. Criar primeiro o registo na nossa BD com estado "Pendente"
        $payment = Payment::create([
            'user_id' => $user->id,
            'amount' => $amount,
            'payment_method' => 'multicaixa',
            'plan_type' => 'pro_monthly',
            'status' => 'pending',
            'expires_at' => now()->addDays(3), // A referência expira em 3 dias
        ]);

        // 4. Falar com os servidores da Proxypay (Ambiente de Sandbox/Testes)
        $proxypayToken = config('services.proxypay.key', env('PROXYPAY_API_KEY'));

        $response = Http::withHeaders([
            'Authorization' => 'Token ' . $proxypayToken,
            'Accept' => 'application/vnd.proxypay.v2+json',
            'Content-Type' => 'application/json',
        ])->post('https://api.sandbox.proxypay.co.ao/references', [
            'amount' => $amount,
            'end_datetime' => $payment->expires_at->format('Y-m-d\TH:i:s\Z'), // Formato de data exigido por eles
            'custom_fields' => [
                // SUPER IMPORTANTE: Enviamos o nosso ID interno para eles devolverem no Webhook!
                'payment_id' => (string) $payment->id, 
                'user_email' => $user->email
            ]
        ]);

        // 5. Analisar a resposta da Proxypay
        if ($response->successful()) {
            $data = $response->json();

            // A Proxypay gerou com sucesso! Atualizamos o nosso pagamento com a Referência
            $payment->update([
                'reference' => $data['number'], // O 'number' é a referência de 9 dígitos
                'entity' => env('PROXYPAY_ENTITY', '12345'), // A tua entidade
            ]);

            // Devolver os dados bonitos para o ecrã do Flutter
            return response()->json([
                'message' => 'Referência gerada com sucesso.',
                'payment_details' => [
                    'entidade' => $payment->entity,
                    'referencia' => $payment->reference,
                    'montante' => $payment->amount,
                    'expira_em' => $payment->expires_at->format('d/m/Y H:i'),
                ]
            ]);
        }

        // 6. Se a Proxypay der erro (falha de rede, manutenção, token inválido)
        Log::error('Erro Proxypay: ' . $response->body());
        
        $payment->update(['status' => 'failed']); // Marcamos como falhado para não ficar pendente para sempre

        return response()->json(['message' => 'Não foi possível gerar a referência Multicaixa no momento. Tenta novamente mais tarde.'], 500);
    }

    public function webhookConfirmation(Request $request)
    {
        // 1. SEGURANÇA: Validar se o pedido veio mesmo da Proxypay
        $signature = $request->header('X-Signature');
        $apiKey = config('services.proxypay.key', env('PROXYPAY_API_KEY'));
        
        // A Proxypay assina o corpo do pedido usando HMAC-SHA256
        $expectedSignature = hash_hmac('sha256', $request->getContent(), $apiKey);

        if (!$signature || !hash_equals($expectedSignature, $signature)) {
            Log::warning('Tentativa de Webhook falsa detetada!');
            return response()->json(['message' => 'Não autorizado.'], 401);
        }

        // 2. Processar os dados enviados pela Proxypay
        // O formato padrão da Proxypay envia um array de transações dentro de 'events' ou diretamente no root dependendo da config.
        // Vamos capturar a referência e o ID customizado que enviámos antes.
        $referenceNumber = $request->input('reference.number');
        $customFields = $request->input('reference.custom_fields');
        
        // Procuramos o pagamento na nossa base de dados
        // Usamos o 'payment_id' que guardámos nos custom_fields (muito mais rápido e seguro)
        $paymentId = $customFields['payment_id'] ?? null;
        
        $payment = Payment::where('id', $paymentId)
            ->orWhere('reference', $referenceNumber)
            ->first();

        if (!$payment) {
            Log::error("Pagamento não encontrado para a referência: {$referenceNumber}");
            return response()->json(['message' => 'Pagamento não encontrado.'], 404);
        }

        // 3. Se o pagamento já foi processado antes, ignoramos para não duplicar (Idempotência)
        if ($payment->status === 'completed') {
            return response()->json(['message' => 'Webhook já processado anteriormente.'], 200);
        }

        // 4. ATUALIZAR O PAGAMENTO (Mudar para Concluído)
        $payment->update([
            'status' => 'completed',
            'paid_at' => now(),
        ]);

        // 5. ATIVAR O PLANO PRO NO UTILIZADOR
        $user = $payment->user;
        
        // Se ele já for PRO, somamos 30 dias à data que ele já tinha. Se for FREE, conta a partir de hoje.
        $currentExpiry = $user->pro_expires_at && $user->pro_expires_at->isFuture() 
            ? $user->pro_expires_at 
            : now();

        $user->update([
            'pro_expires_at' => $currentExpiry->addDays(30), // Adiciona 1 mês de acesso Pro
        ]);

        Log::info("Plano Pro ativado com sucesso para o utilizador: {$user->email}");

        // 6. Responder à Proxypay com 200 OK para eles saberem que recebemos com sucesso
        return response()->json(['message' => 'Pagamento processado e Plano Pro ativo!'], 200);
    }
}