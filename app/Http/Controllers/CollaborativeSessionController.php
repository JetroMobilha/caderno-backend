<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\CollaborativeSession;
use App\Models\CollaborativeSessionParticipant;
use App\Models\Notebook;
use Illuminate\Support\Facades\Log;

class CollaborativeSessionController extends Controller
{
    public function webhook(Request $request)
    {
        // 🛡️ Segurança: Validar secret do Pusher/Reverb em produção
        $events = $request->input('events', []);

        foreach ($events as $event) {
            $channel = $event['channel'];
            if (!str_starts_with($channel, 'presence-notebook.')) continue;

            $notebookId = str_replace('presence-notebook.', '', $channel);
            $userId = $event['user_id'];
            $name = $event['name'];

            $session = CollaborativeSession::firstOrCreate(
                ['notebook_id' => $notebookId, 'is_active' => true],
                ['started_at' => now()]
            );

            if ($name === 'member_added') {
                CollaborativeSessionParticipant::updateOrCreate(
                    ['session_id' => $session->id, 'user_id' => $userId, 'left_at' => null],
                    ['joined_at' => now(), 'socket_id' => $event['socket_id'] ?? null]
                );
            } elseif ($name === 'member_removed') {
                $participant = CollaborativeSessionParticipant::where('session_id' => $session->id)
                    ->where('user_id', $userId)
                    ->whereNull('left_at')
                    ->first();

                if ($participant) {
                    $participant->update(['left_at' => now()]);
                }

                // Se não houver mais ninguém, fechar sessão
                if ($session->activeParticipants()->count() === 0) {
                    $session->update(['is_active' => false, 'ended_at' => now()]);
                }
            }
        }

        return response()->json(['status' => 'ok']);
    }

    public function getStatus(Request $request, $notebook_id)
    {
        $session = CollaborativeSession::where('notebook_id', $notebook_id)
            ->where('is_active', true)
            ->first();

        if (!$session) {
            return response()->json(['active' => false]);
        }

        // Eleger autoridade: Quem entrou primeiro e ainda está na sala
        $authority = $session->activeParticipants()
            ->orderBy('joined_at', 'asc')
            ->with('user:id,name')
            ->first();

        return response()->json([
            'active' => true,
            'authority_id' => $authority ? $authority->user_id : null,
            'authority_name' => $authority ? $authority->user->name : null,
            'participants_count' => $session->activeParticipants()->count(),
            'started_at' => $session->started_at,
        ]);
    }
}
