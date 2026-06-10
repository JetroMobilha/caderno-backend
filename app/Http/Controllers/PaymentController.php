<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Payment;
use App\Models\User;

class PaymentController extends Controller
{
    // 1. O Flutter pede para gerar uma referência Multicaixa
    public function generateReference(Request $request)
    {
        $user = $request->user();
        $amount = 2500.00; // Preço fixo do teu Plano Pro (exemplo)

        // Aqui, no futuro, vais fazer um HTTP Request (usando o Http::post do Laravel)
        // para a API da Proxypay ou Deya para pedir a referência real.
        // Por agora, vamos simular que a API te devolveu estes dados:
        
        $mockEntity = "12345";
        $mockReference = rand(100000000, 999999999); // Simula uma ref de 9 dígitos

        // Registamos na nossa base de dados como PENDENTE
        $payment = Payment::create([
            'user_id' => $user->id,
            'amount' => $amount,
            'payment_method' => 'multicaixa',
            'entity' => $mockEntity,
            'reference' => $mockReference,
            'status' => 'pending'
        ]);

        return response()->json([
            'message' => 'Referência gerada com sucesso. Aguardando pagamento.',
            'payment_details' => [
                'entidade' => $payment->entity,
                'referencia' => $payment->reference,
                'montante' => $payment->amount,
            ]
        ]);
    }

    // 2. O Agregador (Proxypay) chama esta rota automaticamente quando o cliente paga
    public function webhookConfirmation(Request $request)
    {
        // A API de pagamento envia-nos a referência que acabou de ser paga
        $referencePaid = $request->input('reference');

        // Procuramos esse pagamento na nossa BD
        $payment = Payment::where('reference', $referencePaid)->first();

        if ($payment && $payment->status === 'pending') {
            // Atualizamos o pagamento para COMPLETO
            $payment->update(['status' => 'completed']);

            // MÁGICA: Promovemos o utilizador para o plano Pro!
            $user = User::find($payment->user_id);
            $user->update(['plan_type' => 'pro']);

            return response()->json(['message' => 'Pagamento confirmado e conta atualizada!'], 200);
        }

        return response()->json(['message' => 'Pagamento não encontrado ou já processado.'], 404);
    }
}