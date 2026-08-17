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

        $session = CollaborativeSession::where('notebook_id', $notebook_id)->where('is_active', true)->first();

        if (!$session) {
            $session = CollaborativeSession::create([
                'notebook_id' => $notebook_id,
                'is_active' => true,
                'started_at' => now(),
                'sharing_type' => $sharingType,
                'alternative_title' => $alternativeTitle
            ]);
        } else {
            // Se já existe e o dono está a entrar, respeitar as novas definições enviadas
            if ($userRole === 'owner') {
                $session->update([
                    'sharing_type' => $sharingType,
                    'alternative_title' => $alternativeTitle ?? $session->alternative_title
                ]);
            }
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

        // 🚀 LISTA DE PÁGINAS AUTORIZADAS (Para o App saber o que mostrar)
        $authorizedPageIds = CollaborativeSessionPage::where('session_id', $session->id)
            ->pluck('page_id')
            ->toArray();

        // 🚀 GERAR SUMÁRIO DE PÁGINAS FILTRADO (Para Otimização de Sync)
        $query = Page::where('notebook_id', $notebook_id);
        if ($session->sharing_type === 'scoped' && $userRole !== 'owner') {
            $query->whereIn('id', $authorizedPageIds);
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
            'authorized_page_ids' => $authorizedPageIds, // 🚀 FUNDAMENTAL PARA O CONVIDADO
            'pages_summary' => $pagesSummary, // 🚀 Sumário leve para alinhamento rápido
        ]);
    }

    /**
     * Permite ao dono adicionar ou remover páginas à sessão e atualizar metadados.
     */
    public function sharePages(Request $request, $notebook_id)
    {
        $user = $request->user();
        $pageIds = $request->input('page_ids', []);
        $sharingType = $request->input('sharing_type');
        $alternativeTitle = $request->input('alternative_title');

        $session = CollaborativeSession::where('notebook_id', $notebook_id)->where('is_active', true)->first();
        if (!$session) {
             // Se não há sessão ativa, apenas guardamos nas configurações persistentes (fallback para updateSettings)
             return $this->updateSettings($request, $notebook_id);
        }

        $notebook = $session->notebook;
        if (!$notebook->subject || $notebook->subject->user_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($sharingType) $session->sharing_type = $sharingType;
        if ($alternativeTitle) $session->alternative_title = $alternativeTitle;
        $session->save();

        // Se for scoped, atualizar whitelist de páginas
        if ($session->sharing_type === 'scoped') {
            // Limpar anteriores e adicionar novas
            CollaborativeSessionPage::where('session_id', $session->id)->delete();
            foreach ($pageIds as $pid) {
                CollaborativeSessionPage::updateOrCreate([
                    'session_id' => $session->id,
                    'page_id' => $pid
                ]);
            }
        }

        // 🚀 NOTIFICAR TODOS OS PARTICIPANTES DA MUDANÇA DE ESTRUTURA/PERMISSÕES
        $this->broadcastStructureUpdate($session);

        return response()->json(['status' => 'pages_updated', 'count' => count($pageIds)]);
    }

    /**
     * Guarda as configurações de partilha sem precisar de uma sessão ativa.
     */
    public function updateSettings(Request $request, $notebook_id)
    {
        $user = $request->user();
        $notebook = Notebook::findOrFail($notebook_id);

        if ($notebook->subject->user_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $sharingType = $request->input('sharing_type', 'full');
        $alternativeTitle = $request->input('alternative_title');
        $pageIds = $request->input('page_ids', []);

        // Procurar última sessão ativa ou criar uma persistente
        $session = CollaborativeSession::where('notebook_id', $notebook_id)->orderBy('id', 'desc')->first();

        if ($session) {
            $session->update([
                'sharing_type' => $sharingType,
                'alternative_title' => $alternativeTitle
            ]);
        } else {
            $session = CollaborativeSession::create([
                'notebook_id' => $notebook_id,
                'is_active' => false,
                'sharing_type' => $sharingType,
                'alternative_title' => $alternativeTitle,
                'started_at' => now()
            ]);
        }

        if ($sharingType === 'scoped') {
            CollaborativeSessionPage::where('session_id', $session->id)->delete();
            foreach ($pageIds as $pid) {
                CollaborativeSessionPage::create(['session_id' => $session->id, 'page_id' => $pid]);
            }
        }

        // 🚀 Se a sessão estiver ativa, notificar os participantes imediatamente
        if ($session->is_active) {
            $this->broadcastStructureUpdate($session);
        }

        return response()->json(['status' => 'settings_saved']);
    }

    private function broadcastStructureUpdate(CollaborativeSession $session)
    {
        $notebook = $session->notebook;
        $query = Page::where('notebook_id', $notebook->id);

        $authorizedPageIds = null;
        if ($session->sharing_type === 'scoped') {
            $authorizedPageIds = CollaborativeSessionPage::where('session_id', $session->id)->pluck('page_id')->toArray();
            $query->whereIn('id', $authorizedPageIds);
        }

        $pagesSummary = $query->get()->map(function($p) {
            return [
                'id' => $p->id,
                'client_id' => $p->client_id,
                'page_number' => $p->page_number,
                'updated_at_ms' => $p->updated_at_ms,
                'fingerprint' => $p->generateFingerprint(),
            ];
        });

        event(new \App\Events\NotebookStructureUpdated(
            $notebook,
            $pagesSummary->toArray(),
            $session->alternative_title,
            $session->sharing_type,
            $authorizedPageIds
        ));
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

        // 🚀 RECUPERAR A ÚLTIMA SESSÃO (Mesmo que inativa)
        $session = CollaborativeSession::where('notebook_id', $notebook_id)
            ->orderBy('started_at', 'desc')
            ->first();

        if (!$session) {
            Log::warning("⚠️ [Session] Nenhuma sessão prévia encontrada para caderno $notebook_id");
            return response()->json(['active' => false]);
        }

        $authority = $this->electAuthority($session);

        // 🚀 LISTA DE PÁGINAS AUTORIZADAS
        $authorizedPages = CollaborativeSessionPage::where('session_id', $session->id)
            ->pluck('page_id')
            ->toArray();

        return response()->json([
            'active' => (bool)$session->is_active,
            'authority_id' => $authority ? $authority->user_id : null,
            'authority_name' => $authority ? $authority->user->name : null,
            'participants_count' => $session->is_active ? $session->activeParticipants()->count() : 0,
            'started_at' => $session->started_at,
            'sharing_type' => $session->sharing_type,
            'alternative_title' => $session->alternative_title,
            'authorized_page_ids' => $authorizedPages,
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
