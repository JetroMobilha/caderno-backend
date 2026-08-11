<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\CollaborativeSession;
use App\Models\CollaborativeSessionParticipant;
use App\Models\Notebook;
use Illuminate\Support\Facades\Log;

class CollaborativeSessionController extends Controller
{
    /**
     * Webhook do Reverb.
     */
    public function webhook(Request $request)
    {
        // 🚀 LOG TOTAL: Capturar exatamente o que o Reverb envia
        Log::info("📡 [Webhook] Reverb Payload:", $request->all());

        $events = $request->input('events', []);

        foreach ($events as $event) {
            $evtName = $event['name'] ?? '';
            $channel = $event['channel'] ?? '';
            $userId = $event['user_id'] ?? null;

            if (!$userId || !str_starts_with($channel, 'presence-notebook.')) {
                Log::warning("⚠️ [Webhook] Ignorando evento inválido ou canal errado.", ['channel' => $channel, 'user' => $userId]);
                continue;
            }

            $notebookId = str_replace('presence-notebook.', '', $channel);

            // 🛡️ Verificar se o caderno existe
            $notebook = Notebook::find($notebookId);
            if (!$notebook) {
                Log::error("❌ [Webhook] Caderno $notebookId não encontrado na DB.");
                continue;
            }

            $session = CollaborativeSession::firstOrCreate(
                ['notebook_id' => $notebookId, 'is_active' => true],
                ['started_at' => now()]
            );

            if ($evtName === 'member_added') {
                Log::info("🟢 [Webhook] Registando entrada do utilizador $userId na sessão {$session->id}");
                CollaborativeSessionParticipant::updateOrCreate(
                    ['session_id' => $session->id, 'user_id' => $userId, 'left_at' => null],
                    [
                        'joined_at' => now(),
                        'socket_id' => $event['socket_id'] ?? null
                    ]
                );
            } elseif ($evtName === 'member_removed') {
                Log::info("🔴 [Webhook] Registando saída do utilizador $userId");
                $participant = CollaborativeSessionParticipant::where('session_id', $session->id)
                    ->where('user_id', $userId)
                    ->whereNull('left_at')
                    ->first();

                if ($participant) {
                    $participant->update(['left_at' => now()]);
                }

                if ($session->activeParticipants()->count() === 0) {
                    Log::info("🧹 [Webhook] Sala vazia. Encerrando sessão {$session->id}");
                    $session->update(['is_active' => false, 'ended_at' => now()]);
                }
            }
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Retorna o status da sessão e quem é a autoridade atual.
     */
    public function getStatus(Request $request, $notebook_id)
    {
        $session = CollaborativeSession::where('notebook_id', $notebook_id)
            ->where('is_active', true)
            ->first();

        if (!$session) {
            return response()->json(array('active' => false));
        }

        $authority = $session->activeParticipants()
            ->orderBy('joined_at', 'asc')
            ->with('user')
            ->first();

        $authorityId = $authority ? $authority->user_id : null;
        $authorityName = ($authority && $authority->user) ? $authority->user->name : null;
        $participantsCount = $session->activeParticipants()->count();

        return response()->json(array(
            'active' => true,
            'authority_id' => $authorityId,
            'authority_name' => $authorityName,
            'participants_count' => $participantsCount,
            'started_at' => $session->started_at
        ));
    }
}
