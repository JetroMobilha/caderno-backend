<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Events\WebRtcSignalReceived;
use App\Models\Notebook;

class WebRtcController extends Controller
{
    public function signal(Request $request, $notebook_id)
    {
        // 1. Validar o sinal (Pode ser uma "Offer", "Answer" ou "Ice Candidate")
        $validated = $request->validate([
            'type' => 'required|string', 
            'payload' => 'required',     
            'receiver_id' => 'nullable|integer' 
        ]);

        // Valida se o utilizador é dono OU se o caderno foi partilhado com ele
        $notebook = $request->user()->notebooks()->find($notebook_id) 
                 ?? $request->user()->sharedNotebooks()->findOrFail($notebook_id);

        // 2. Preparar o pacote de dados
        $data = [
            'sender_id' => $request->user()->id,
            'type' => $validated['type'],
            'payload' => $validated['payload'],
            'receiver_id' => $validated['receiver_id'] ?? null,
        ];

        // 3. Disparar para todos na sala (exceto para quem enviou)
        broadcast(new WebRtcSignalReceived($notebook->id, $data))->toOthers();

        return response()->json(['message' => 'Sinal WebRTC transmitido à velocidade da luz! ⚡']);
    }
}