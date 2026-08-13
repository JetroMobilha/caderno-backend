<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\CollaborativeSession;
use App\Models\CollaborativeSessionParticipant;
use App\Models\Notebook;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class CollaborativeSessionController extends Controller
{
    /**
     * O App avisa que entrou na sala.
     */
    public function join(Request $request, $notebook_id)
    {
        $user = $request->user();
        $notebook = Notebook::find($notebook_id);
        if (!$notebook) return response()->json(['error' => 'Notebook not found'], 404);

        // 🚀 CALCULAR PAPEL REAL DO UTILIZADOR
        $userRole = 'student';
        if ($notebook->subject && $notebook->subject->user_id === $user->id) {
            $userRole = 'owner';
        } else {
            $pivot = DB::table('notebook_user')->where('notebook_id', $notebook->id)->where('user_id', $user->id)->first();
            $userRole = $pivot ? $pivot->role : 'viewer';
        }

        $session = CollaborativeSession::firstOrCreate(
            ['notebook_id' => $notebook_id, 'is_active' => true],
            ['started_at' => now()]
        );

        CollaborativeSessionParticipant::updateOrCreate(
            ['session_id' => $session->id, 'user_id' => $user->id, 'left_at' => null],
            ['role' => $userRole, 'joined_at' => now(), 'last_heartbeat' => now()]
        );

        // Eleger autoridade por hierarquia de papéis e tempo de entrada
        $authority = $this->electAuthority($session);

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

        $authority = $this->electAuthority($session);

        return response()->json([
            'active' => true,
            'authority_id' => $authority ? $authority->user_id : null,
            'authority_name' => $authority ? $authority->user->name : null,
            'participants_count' => $session->activeParticipants()->count(),
            'started_at' => $session->started_at,
        ]);
    }

    /**
     * Eleger o "Líder" da sessão baseado na hierarquia de papéis.
     */
    private function electAuthority(CollaborativeSession $session)
    {
        return CollaborativeSessionParticipant::where('session_id', $session->id)
            ->whereNull('left_at')
            ->select('collaborative_session_participants.*')
            ->join('users', 'users.id', '=', 'collaborative_session_participants.user_id')
            ->orderByRaw("CASE
                WHEN role = 'owner' THEN 1
                WHEN role = 'editor' THEN 2
                WHEN role = 'student' THEN 3
                ELSE 4 END ASC")
            ->orderBy('joined_at', 'asc')
            ->with('user:id,name')
            ->first();
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
