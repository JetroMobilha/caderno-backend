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
     * O App avisa que entrou na sala.
     */
    public function join(Request $request, $notebook_id)
    {
        $user = $request->user();

        $session = CollaborativeSession::firstOrCreate(
            ['notebook_id' => $notebook_id, 'is_active' => true],
            ['started_at' => now()]
        );

        CollaborativeSessionParticipant::updateOrCreate(
            ['session_id' => $session->id, 'user_id' => $user->id, 'left_at' => null],
            ['joined_at' => now(), 'last_heartbeat' => now()]
        );

        // Eleger autoridade (quem entrou primeiro e ainda está online)
        $authority = $session->activeParticipants()->orderBy('joined_at', 'asc')->first();

        return response()->json([
            'active' => true,
            'session_id' => $session->id,
            'authority_id' => $authority ? $authority->user_id : null,
            'participants_count' => $session->activeParticipants()->count(),
            'started_at' => $session->started_at,
        ]);
    }

    /**
     * O App envia um sinal de vida periódico (Heartbeat).
     */
    public function heartbeat(Request $request, $notebook_id)
    {
        $user = $request->user();

        $participant = CollaborativeSessionParticipant::whereHas('session', function($q) use ($notebook_id) {
            $q->where('notebook_id', $notebook_id)->where('is_active', true);
        })->where('user_id', $user->id)->whereNull('left_at')->first();

        if ($participant) {
            $participant->update(['last_heartbeat' => now()]);
            return response()->json(['status' => 'alive']);
        }

        return response()->json(['status' => 'session_not_found'], 404);
    }

    /**
     * O App avisa que vai sair.
     */
    public function leave(Request $request, $notebook_id)
    {
        $user = $request->user();

        $participant = CollaborativeSessionParticipant::whereHas('session', function($q) use ($notebook_id) {
            $q->where('notebook_id', $notebook_id)->where('is_active', true);
        })->where('user_id', $user->id)->whereNull('left_at')->first();

        if ($participant) {
            $participant->update(['left_at' => now()]);

            $session = $participant->session;
            if ($session->activeParticipants()->count() === 0) {
                $session->update(['is_active' => false, 'ended_at' => now()]);
            }
        }

        return response()->json(['status' => 'left']);
    }

    /**
     * Retorna o status da sessão (usado para consulta rápida).
     */
    public function getStatus(Request $request, $notebook_id)
    {
        $session = CollaborativeSession::where('notebook_id', $notebook_id)
            ->where('is_active', true)
            ->first();

        if (!$session) {
            return response()->json(['active' => false]);
        }

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

    /**
     * Webhook (Mantido apenas como redundância/compatibilidade futura).
     */
    public function webhook(Request $request)
    {
        $events = $request->input('events', []);
        foreach ($events as $event) {
            $evtName = $event['name'] ?? '';
            $channel = $event['channel'] ?? '';
            if (!str_starts_with($channel, 'presence-notebook.')) continue;
            $notebookId = str_replace('presence-notebook.', '', $channel);

            // Aqui poderíamos processar member_added/removed se o Reverb suportasse,
            // mas como não suporta bem, a lógica join/leave/heartbeat acima é a oficial.
        }
        return response()->json(['status' => 'ok']);
    }
}
