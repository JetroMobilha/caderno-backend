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
use Illuminate\Support\Facades\Redis; // 🚀 Adicionado para Histórico Server-Authoritative

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

        // 🚀 RECEBER PÁGINAS SELECIONADAS E METADADOS
        $sharedPageIds = $request->input('page_ids', []);
        $alternativeTitle = $request->input('alternative_title');
        $sharingType = $request->input('sharing_type', 'full');

        $session = CollaborativeSession::where('notebook_id', $notebook_id)->where('is_active', true)->first();

        if (!$session) {
            $session = CollaborativeSession::create([
                'notebook_id' => $notebook_id,
                'is_active' => true,
                'started_at' => now(),
                'sharing_type' => $sharingType,
                'voice_mode' => 'open', // 🚀 Padrão: Aberto
                'alternative_title' => $alternativeTitle
            ]);
        } else {
            if ($userRole === 'owner') {
                $session->update([
                    'sharing_type' => $sharingType ?? $session->sharing_type,
                    'alternative_title' => $alternativeTitle ?? $session->alternative_title
                ]);
            }
        }

        // 🚀 ATUALIZAR WHITELIST DE PÁGINAS (Dono)
        if ($userRole === 'owner' && $session->sharing_type === 'scoped' && !empty($sharedPageIds)) {
            CollaborativeSessionPage::where('session_id', $session->id)->delete();
            foreach ($sharedPageIds as $pid) {
                CollaborativeSessionPage::create(['session_id' => $session->id, 'page_id' => $pid]);
            }
            Log::info("📄 [Session] " . count($sharedPageIds) . " páginas vinculadas à sala.");
        }

        // 🚀 GUARDAR PARTICIPANTE NO REDIS (Em Memória)
        $canSpeak = in_array($userRole, ['owner', 'editor']) ? true : ($session->voice_mode === 'open');
        $participantData = [
            'user_id' => $user->id,
            'name' => $user->name,
            'role' => $userRole,
            'can_speak' => $canSpeak,
            'joined_at' => now()->timestamp,
        ];

        $redisParticipantsKey = "session:{$session->id}:participants";
        Redis::hset($redisParticipantsKey, $user->id, json_encode($participantData));

        // Também damos um pulso inicial no heartbeat
        $redisHeartbeatKey = "session:{$notebook_id}:heartbeat:user:{$user->id}";
        Redis::setex($redisHeartbeatKey, 40, "alive");

        // Registo persistente no MySQL para histórico futuro, mas não vital para o Live
        $participant = CollaborativeSessionParticipant::updateOrCreate(
            ['session_id' => $session->id, 'user_id' => $user->id, 'left_at' => null],
            [
                'role' => $userRole,
                'joined_at' => now(),
                'last_heartbeat' => now(),
                'can_speak' => $canSpeak
            ]
        );

        // Eleger autoridade por hierarquia (AGORA LÊ DO REDIS)
        $authority = $this->electAuthority($session);

        // 🚀 LISTA DE PÁGINAS AUTORIZADAS (Estado Final)
        $authorizedPageIds = CollaborativeSessionPage::where('session_id', $session->id)
            ->pluck('page_id')
            ->toArray();

        // 🚀 GERAR SUMÁRIO DE PÁGINAS FILTRADO COM METADADOS (Título/Rodapé)
        $query = Page::where('notebook_id', $notebook_id);
        if ($session->sharing_type === 'scoped' && $userRole !== 'owner') {
            $query->whereIn('id', $authorizedPageIds);
        }

        $pagesSummary = $query->get(['id', 'client_id', 'page_number', 'updated_at_ms', 'is_frozen', 'paper_size', 'header_data', 'footer_data'])
            ->map(function($p) {
                return [
                    'id' => $p->id,
                    'client_id' => $p->client_id,
                    'page_number' => $p->page_number,
                    'updated_at_ms' => $p->updated_at_ms,
                    'header_data' => $p->header_data, // 🚀 Título
                    'footer_data' => $p->footer_data, // 🚀 Rodapé
                    'fingerprint' => $p->generateFingerprint(),
                ];
            });

        return response()->json([
            'active' => true,
            'session_id' => $session->id,
            'user_role' => $userRole, // 🚀 ROBUSTECIDO: Devolve o papel exato do utilizador (owner/editor/student/viewer)
            'authority_id' => $authority ? $authority->user_id : null,
            'participants_count' => $session->activeParticipants()->count(),
            'started_at' => $session->started_at,
            'alternative_title' => $session->alternative_title,
            'sharing_type' => $session->sharing_type,
            'voice_mode' => $session->voice_mode, // 🚀
            'can_speak' => (bool)$participant->can_speak, // 🚀
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
        $voiceMode = $request->input('voice_mode', 'open'); // 🚀
        $alternativeTitle = $request->input('alternative_title');
        $pageIds = $request->input('page_ids', []);

        // Procurar última sessão ativa ou criar uma persistente
        $session = CollaborativeSession::where('notebook_id', $notebook_id)->orderBy('id', 'desc')->first();

        if ($session) {
            $session->update([
                'sharing_type' => $sharingType,
                'voice_mode' => $voiceMode, // 🚀
                'alternative_title' => $alternativeTitle
            ]);
        } else {
            $session = CollaborativeSession::create([
                'notebook_id' => $notebook_id,
                'is_active' => false,
                'sharing_type' => $sharingType,
                'voice_mode' => $voiceMode, // 🚀
                'alternative_title' => $alternativeTitle,
                'started_at' => now()
            ]);
        }

        // Se o modo de voz mudou, atualizar permissões de quem já está na sala
        if ($session->is_active) {
            $redisParticipantsKey = "session:{$session->id}:participants";
            $allCached = Redis::hgetall($redisParticipantsKey);

            if ($voiceMode === 'open') {
                CollaborativeSessionParticipant::where('session_id', $session->id)->whereNull('left_at')->update(['can_speak' => true]);
                foreach ($allCached as $uid => $jsonStr) {
                    $pData = json_decode($jsonStr, true);
                    $pData['can_speak'] = true;
                    Redis::hset($redisParticipantsKey, $uid, json_encode($pData));
                }
            } elseif ($voiceMode === 'authority_only') {
                CollaborativeSessionParticipant::where('session_id', $session->id)->whereNull('left_at')->whereNotIn('role', ['owner', 'editor'])->update(['can_speak' => false]);
                foreach ($allCached as $uid => $jsonStr) {
                    $pData = json_decode($jsonStr, true);
                    if (!in_array($pData['role'], ['owner', 'editor'])) {
                        $pData['can_speak'] = false;
                        Redis::hset($redisParticipantsKey, $uid, json_encode($pData));
                    }
                }
            }

            event(new \App\Events\VoicePolicyUpdated($session));
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
                'header_data' => $p->header_data, // 🚀 Título
                'footer_data' => $p->footer_data, // 🚀 Rodapé
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
     * 🚀 Agora usa REDIS para Zero-I/O na base de dados (Protege o MySQL).
     */
    public function heartbeat(Request $request, $notebook_id)
    {
        $user = $request->user();

        $redisHeartbeatKey = "session:{$notebook_id}:heartbeat:user:{$user->id}";

        // Define que este utilizador está vivo por 40 segundos.
        // Se não houver ping em 40s, a chave evapora-se e ele é considerado offline.
        Redis::setex($redisHeartbeatKey, 40, "alive");

        return response()->json(['status' => 'alive']);
    }

    /**
     * O App avisa que vai sair.
     */
    public function leave(Request $request, $notebook_id)
    {
        $user = $request->user();

        $session = CollaborativeSession::where('notebook_id', $notebook_id)->where('is_active', true)->first();
        if ($session) {
            // 🚀 LIMPEZA REDIS
            $redisParticipantsKey = "session:{$session->id}:participants";
            Redis::hdel($redisParticipantsKey, $user->id);
            Redis::del("session:{$notebook_id}:heartbeat:user:{$user->id}");

            // Verifica quantos ativos sobraram no Redis
            $activeCount = count($this->getActiveParticipantsFromRedis($session));
            if ($activeCount === 0) {
                $session->update(['is_active' => false, 'ended_at' => now()]);
            }

            // Registo no MySQL
            CollaborativeSessionParticipant::where('session_id', $session->id)
                ->where('user_id', $user->id)
                ->whereNull('left_at')
                ->update(['left_at' => now()]);
        }

        return response()->json(['status' => 'left']);
    }

    /**
     * Retorna o status da sessão (usado para consulta rápida).
     */
    public function getStatus(Request $request, $notebook_id)
    {
        $user = $request->user();
        Log::info("🔍 [Session] Consulta de status para caderno $notebook_id");

        // 🚀 RECUPERAR A ÚLTIMA SESSÃO (Mesmo que inativa)
        $session = CollaborativeSession::where('notebook_id', $notebook_id)
            ->orderBy('started_at', 'desc')
            ->first();

        if (!$session) {
            Log::warning("⚠️ [Session] Nenhuma sessão prévia encontrada para caderno $notebook_id");
            return response()->json(['active' => false]);
        }

        $activeParticipants = $session->is_active ? $this->getActiveParticipantsFromRedis($session) : [];
        $authority = $this->electAuthority($session, $activeParticipants);

        // 🚀 LISTA DE PÁGINAS AUTORIZADAS
        $authorizedPages = CollaborativeSessionPage::where('session_id', $session->id)
            ->pluck('page_id')
            ->toArray();

        // Extrair os meus dados do Redis (ou falso)
        $myParticipantData = collect($activeParticipants)->firstWhere('user_id', $user->id);

        return response()->json([
            'active' => (bool)$session->is_active,
            'authority_id' => $authority['user_id'] ?? null,
            'authority_name' => $authority['name'] ?? null,
            'participants_count' => count($activeParticipants),
            'started_at' => $session->started_at,
            'sharing_type' => $session->sharing_type,
            'voice_mode' => $session->voice_mode,
            'can_speak' => $myParticipantData ? (bool)$myParticipantData['can_speak'] : false,
            'alternative_title' => $session->alternative_title,
            'authorized_page_ids' => $authorizedPages,
        ]);
    }

    /**
     * Recupera os participantes que estão realmente online através da memória REDIS (Heartbeats)
     */
    private function getActiveParticipantsFromRedis(CollaborativeSession $session)
    {
        $redisParticipantsKey = "session:{$session->id}:participants";
        $allCached = Redis::hgetall($redisParticipantsKey);

        $active = [];
        foreach ($allCached as $userId => $jsonStr) {
            $isAlive = Redis::exists("session:{$session->notebook_id}:heartbeat:user:{$userId}");
            if ($isAlive) {
                $active[] = json_decode($jsonStr, true);
            } else {
                // Garbage Collection: remove do Hash se o heartbeat já expirou
                Redis::hdel($redisParticipantsKey, $userId);
            }
        }
        return $active;
    }

    /**
     * Eleger o "Líder" da sessão baseado na hierarquia de papéis. (MEMÓRIA REDIS)
     */
    private function electAuthority(CollaborativeSession $session, $activeParticipants = null)
    {
        if ($activeParticipants === null) {
            $activeParticipants = $this->getActiveParticipantsFromRedis($session);
        }

        if (empty($activeParticipants)) return null;

        // Ordenar por hierarquia de papel e data de entrada
        usort($activeParticipants, function($a, $b) {
            $roleWeights = ['owner' => 1, 'editor' => 2, 'student' => 3];
            $weightA = $roleWeights[$a['role']] ?? 4;
            $weightB = $roleWeights[$b['role']] ?? 4;

            if ($weightA !== $weightB) return $weightA <=> $weightB;
            return $a['joined_at'] <=> $b['joined_at'];
        });

        return $activeParticipants[0];
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

    /**
     * Permite ao dono/editor autorizar ou bloquear a voz de um participante específico.
     */
    public function toggleParticipantVoice(Request $request, $notebook_id)
    {
        $user = $request->user();
        $targetUserId = $request->input('user_id');
        $allowed = $request->input('allowed', false);

        $session = CollaborativeSession::where('notebook_id', $notebook_id)->where('is_active', true)->firstOrFail();

        // Validar se quem pede tem autoridade
        $notebook = $session->notebook;
        $isOwner = $notebook->subject && $notebook->subject->user_id === $user->id;
        $isEditor = DB::table('notebook_user')->where('notebook_id', $notebook->id)->where('user_id', $user->id)->where('role', 'editor')->exists();

        if (!$isOwner && !$isEditor) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $participant = CollaborativeSessionParticipant::where('session_id', $session->id)
            ->where('user_id', $targetUserId)
            ->whereNull('left_at')
            ->firstOrFail();

        $participant->update(['can_speak' => $allowed]);

        // 🚀 Atualizar na Cache Redis
        $redisParticipantsKey = "session:{$session->id}:participants";
        $pDataStr = Redis::hget($redisParticipantsKey, $targetUserId);
        if ($pDataStr) {
            $pData = json_decode($pDataStr, true);
            $pData['can_speak'] = $allowed;
            Redis::hset($redisParticipantsKey, $targetUserId, json_encode($pData));
        }

        event(new \App\Events\VoicePolicyUpdated($session, $targetUserId, $allowed));

        return response()->json(['status' => 'voice_updated', 'user_id' => $targetUserId, 'allowed' => $allowed]);
    }

    /**
     * 🚀 GUARDA UMA AÇÃO NO HISTÓRICO DA SESSÃO (REDIS)
     * Isto permite que novos utilizadores recuperem o histórico sem pedir a colegas via WebSocket.
     */
    public function recordAction(Request $request, $notebook_id)
    {
        $action = $request->input('action');
        if (!$action) {
            return response()->json(['error' => 'No action provided'], 400);
        }

        // Extrai o timestamp para a filtragem. Tenta procurar no action_data, ou na raiz
        $timestamp = $action['data']['timestamp'] ?? $action['timestamp'] ?? round(microtime(true) * 1000);
        $action['server_ts'] = $timestamp;

        $redisKey = "session:{$notebook_id}:history";

        // Guardar no Redis como uma lista (JSON)
        Redis::rpush($redisKey, json_encode($action));

        // Define que o histórico expira após 4 horas (evitar lixo se a sessão morrer)
        Redis::expire($redisKey, 60 * 60 * 4);

        return response()->json(['status' => 'recorded']);
    }

    /**
     * 🚀 RETORNA O HISTÓRICO DA SESSÃO PARA SINCRONIZAÇÃO APÓS HIBERNAÇÃO
     */
    public function getHistory(Request $request, $notebook_id)
    {
        $since = $request->input('since', 0); // Timestamp em milissegundos
        $redisKey = "session:{$notebook_id}:history";

        // Obtém todas as ações guardadas
        $actionsJson = Redis::lrange($redisKey, 0, -1);
        $filteredActions = [];

        foreach ($actionsJson as $jsonStr) {
            $action = json_decode($jsonStr, true);
            $ts = $action['server_ts'] ?? $action['data']['timestamp'] ?? 0;

            if ($ts >= $since) {
                $filteredActions[] = $action;
            }
        }

        return response()->json([
            'status' => 'ok',
            'count' => count($filteredActions),
            'actions' => $filteredActions
        ]);
    }

    /**
     * 💬 GUARDA UMA MENSAGEM DE CHAT NA SESSÃO (REDIS)
     */
    public function recordChat(Request $request, $notebook_id)
    {
        $message = $request->input('message');
        if (!$message) {
            return response()->json(['error' => 'No message provided'], 400);
        }

        $redisKey = "session:{$notebook_id}:chat";

        // Guardar no Redis como uma lista (JSON)
        Redis::rpush($redisKey, json_encode($message));

        // Limita o histórico de chat às últimas 100 mensagens (Opcional, mas recomendado)
        Redis::ltrim($redisKey, -100, -1);

        // Define que o histórico expira após 4 horas
        Redis::expire($redisKey, 60 * 60 * 4);

        return response()->json(['status' => 'recorded']);
    }

    /**
     * 💬 RETORNA O HISTÓRICO DE CHAT DA SESSÃO
     */
    public function getChatHistory(Request $request, $notebook_id)
    {
        $redisKey = "session:{$notebook_id}:chat";
        $messagesJson = Redis::lrange($redisKey, 0, -1);

        $messages = array_map(function($jsonStr) {
            return json_decode($jsonStr, true);
        }, $messagesJson);

        return response()->json([
            'status' => 'ok',
            'messages' => $messages
        ]);
    }
}
