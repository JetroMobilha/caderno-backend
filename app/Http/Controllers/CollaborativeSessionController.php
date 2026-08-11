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
     * Recebe eventos de presença do Reverb (Webhooks).
     */
    public function webhook(Request $request)
    {
        $events = $request->input('events', []);
        Log::info("📡 [Webhook] Recebidos " . count($events) . " eventos do Reverb.");

        foreach ($events as $event) {
            $evtName = $event['name'] ?? '';
            $channel = $event['channel'] ?? '';
            $userId = $event['user_id'] ?? null;

            if (!$userId || !str_starts_with($channel, 'presence-notebook.')) {
                continue;
            }

            Log::info("🔔 [Webhook] Evento: $evtName no canal: $channel para user: $userId");

            $notebookId = str_replace('presence-notebook.', '', $channel);

            // Encontrar ou criar sessão ativa para o caderno
            $session = CollaborativeSession::firstOrCreate(
                ['notebook_id' => $notebookId, 'is_active' => true],
                ['started_at' => now()]
            );

            if ($evtName === 'member_added') {
                CollaborativeSessionParticipant::updateOrCreate(
                    ['session_id' => $session->id, 'user_id' => $userId, 'left_at' => null],
                    ['joined_at' => now(), 'socket_id' => $event['socket_id'] ?? null]
                );
            } elseif ($evtName === 'member_removed') {
                $participant = CollaborativeSessionParticipant::where('session_id', $session->id)
                    ->where('user_id', $userId)
                    ->whereNull('left_at')
                    ->first();

                if ($participant) {
                    $participant->update(['left_at' => now()]);
                }

                // Se a sala ficou vazia, encerramos a sessão
                if ($session->activeParticipants()->count() === 0) {
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
            return response()->json(['active' => false]);
        }

        // A autoridade é quem entrou primeiro e ainda não saiu
        $authority = $session->activeParticipants()
            ->orderBy('joined_at', 'asc')
            ->with('user:id,name')
            ->first();

        return response()->json([
            'active' => true,
            'authority_id' => $authority?->user_id,
            'authority_name' => $authority?->user?->name,
            'participants_count' => $session->activeParticipants()->count(),
            'started_at' => $session->started_at,
        ]);
    }
}
