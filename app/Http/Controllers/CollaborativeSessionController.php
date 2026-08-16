<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\CollaborativeSession;
use App\Models\CollaborativeSessionParticipant;
use App\Models\CollaborativeSessionPage;
use App\Models\Notebook;
use App\Models\Page;
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
        Log::info("🎯 [Session] Utilizador {$user->id} a tentar entrar no caderno $notebook_id");

        $notebook = Notebook::find($notebook_id);
        if (!$notebook) {
            Log::error("❌ [Session] Caderno $notebook_id não encontrado.");
            return response()->json(['error' => 'Notebook not found'], 404);
        }

        // Calcular papel
        $userRole = 'student';
        if ($notebook->subject && $notebook->subject->user_id === $user->id) {
            $userRole = 'owner';
        } else {
            $pivot = DB::table('notebook_user')->where('notebook_id', $notebook->id)->where('user_id', $user->id)->first();
            $userRole = $pivot ? $pivot->role : 'viewer';
        }
        Log::info("👤 [Session] Papel detetado: $userRole");

        // 🚀 RECEBER PÁGINAS SELECIONADAS, TÍTULO ALTERNATIVO E TIPO DE PARTILHA
        $sharedPageIds = $request->input('page_ids', []);
        $alternativeTitle = $request->input('alternative_title');
        $sharingType = $request->input('sharing_type', 'full'); // full ou scoped

        $session = CollaborativeSession::firstOrCreate(
            ['notebook_id' => $notebook_id, 'is_active' => true],
            ['started_at' => now(), 'sharing_type' => $sharingType]
        );

        if ($alternativeTitle) {
            $session->update(['alternative_title' => $alternativeTitle]);
        }

        // Se mudou o tipo de partilha, atualizar (Dono apenas)
        if ($userRole === 'owner' && $sharingType !== $session->sharing_type) {
            $session->update(['sharing_type' => $sharingType]);
        }

        Log::info("🛋️ [Session] ID da sessão: {$session->id}");

        // Se o dono enviou novas páginas e o modo é scoped, associá-las à sessão
        if ($userRole === 'owner' && $session->sharing_type === 'scoped' && !empty($sharedPageIds)) {
            foreach ($sharedPageIds as $pid) {
                CollaborativeSessionPage::updateOrCreate([
                    'session_id' => $session->id,
                    'page_id' => $pid
                ]);
            }
            Log::info("📄 [Session] " . count($sharedPageIds) . " páginas vinculadas à sala.");
        }

        $participant = CollaborativeSessionParticipant::updateOrCreate(
            ['session_id' => $session->id, 'user_id' => $user->id, 'left_at' => null],
            ['role' => $userRole, 'joined_at' => now(), 'last_heartbeat' => now()]
        );

        // Eleger autoridade por hierarquia
        $authority = $this->electAuthority($session);

        // 🚀 GERAR SUMÁRIO DE PÁGINAS AUTORIZADAS (Para Otimização de Sync)
        $query = Page::where('notebook_id', $notebook_id);
        if ($session->sharing_type === 'scoped' && $userRole !== 'owner') {
            $sharedPageIds = CollaborativeSessionPage::where('session_id', $session->id)->pluck('page_id');
            $query->whereIn('id', $sharedPageIds);
        }

        $pagesSummary = $query->get(['id', 'client_id', 'page_number', 'updated_at_ms', 'is_frozen', 'paper_size', 'stroke_data', 'text_data', 'image_data'])
            ->map(function($p) {
                return [
                    'id' => $p->id,
                    'client_id' => $p->client_id,
                    'page_number' => $p->page_number,
                    'updated_at_ms' => $p->updated_at_ms,
                    'fingerprint' => $p->generateFingerprint(), // ⚡ A mágica acontece aqui
                ];
            });

        return response()->json([
            'active' => true,
            'session_id' => $session->id,
            'authority_id' => $authority ? $authority->user_id : null,
            'participants_count' => $session->activeParticipants()->count(),
            'started_at' => $session->started_at,
            'alternative_title' => $session->alternative_title,
            'sharing_type' => $session->sharing_type,
            'pages_summary' => $pagesSummary, // 🚀 Sumário leve para alinhamento rápido
        ]);
    }

    /**
     * Permite ao dono adicionar mais páginas à sessão em tempo real.
     */
    public function sharePages(Request $request, $notebook_id)
    {
        $user = $request->user();
        $pageIds = $request->input('page_ids', []);

        $session = CollaborativeSession::where('notebook_id', $notebook_id)->where('is_active', true)->first();
        if (!$session) return response()->json(['error' => 'No active session'], 404);

        $notebook = $session->notebook;
        if (!$notebook->subject || $notebook->subject->user_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        foreach ($pageIds as $pid) {
            CollaborativeSessionPage::updateOrCreate([
                'session_id' => $session->id,
                'page_id' => $pid
            ]);
        }

        return response()->json(['status' => 'pages_shared', 'count' => count($pageIds)]);
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
        Log::info("🔍 [Session] Consulta de status para caderno $notebook_id");

        $session = CollaborativeSession::where('notebook_id', $notebook_id)
            ->where('is_active', true)
            ->orderBy('started_at', 'desc')
            ->first();

        if (!$session) {
            Log::warning("⚠️ [Session] Nenhuma sessão ativa encontrada para caderno $notebook_id");
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
