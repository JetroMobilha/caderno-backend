<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPageOcr;
use App\Services\SyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\CollaborativeSession;
use App\Models\CollaborativeSessionPage;
use App\Models\Subject;
use App\Models\Notebook;
use App\Models\Page;
use App\Models\LessonRecording;
use App\Events\PageDeleted;
use App\Events\PageUpdated;
use App\Events\NotebookDeleted;
use App\Events\SyncRequested;

class SyncController extends Controller
{
    public function __construct(protected SyncService $syncService) {}
    // =========================================================================
    // 📚 1. SINCRONIZAÇÃO DE DISCIPLINAS
    // =========================================================================
    public function push(Request $request)
    {
        $user = $request->user();
        $clientSubjects = $request->input('subjects', []);
        $syncedSubjects = [];

        foreach ($clientSubjects as $data) {
            $incomingTime = (int)($data['updated_at'] ?? 0);

            // 🎯 BUSCA HÍBRIDA: Tenta ID do Servidor primeiro, depois o UUID
            $subject = null;
            if (!empty($data['server_id'])) {
                $subject = Subject::withTrashed()->where('user_id', $user->id)->find($data['server_id']);
            }
            if (!$subject) {
                $subject = Subject::withTrashed()->where('user_id', $user->id)->where('client_id', $data['client_id'])->first();
            }

            // 🗑️ Deleção LWW
            if (!empty($data['is_deleted']) && $data['is_deleted'] == 1) {
                if ($subject && !$subject->trashed() && $incomingTime > ($subject->updated_at_ms ?? 0)) {
                    $subject->update(['updated_at_ms' => $incomingTime]);
                    $subject->delete();
                }
                continue;
            }

            // Se encontrou, atualiza. Se não, cria.
            if ($subject) {
                // 🚀 LWW
                if ($incomingTime >= ($subject->updated_at_ms ?? 0)) {
                    if ($subject->trashed()) $subject->restore();
                    $subject->update([
                        'client_id' => $data['client_id'],
                        'name'      => trim($data['name'] ?? $subject->name),
                        'color'     => $data['color'] ?? $subject->color,
                        'icon'      => $data['icon'] ?? $subject->icon,
                        'updated_at_ms' => $incomingTime,
                    ]);
                }
            } else {
                $subject = Subject::create([
                    'user_id'   => $user->id,
                    'client_id' => $data['client_id'],
                    'name'      => trim($data['name'] ?? 'Nova Disciplina'),
                    'color'     => $data['color'] ?? '#000000',
                    'icon'      => $data['icon'] ?? 'default-icon',
                    'updated_at_ms' => $incomingTime,
                ]);
            }

            $syncedSubjects[] = ['client_id' => $data['client_id'], 'server_id' => $subject->id];
        }

        return response()->json(['message' => 'OK', 'synced_subjects' => $syncedSubjects]);
    }

    public function pull(Request $request)
    {
        $user = $request->user();
        $lastSyncedAt = $request->query('last_synced_at');

        $query = Subject::withTrashed()->where('user_id', $user->id);
        if ($lastSyncedAt) { $query->where('updated_at', '>', $lastSyncedAt); }

        $paginatedSubjects = $query->paginate(50);

        return response()->json([
            'data' => $paginatedSubjects->items(),
            'links' => $paginatedSubjects->linkCollection(),
            'meta' => [
                'server_time' => now()->format('Y-m-d\TH:i:s\Z'),
                'server_time_ms' => (int)(microtime(true) * 1000)
            ]
        ]);
    }


    // =========================================================================
    // 📓 2. SINCRONIZAÇÃO DE CADERNOS (MONETIZAÇÃO + VERIFICAÇÃO DE ROLES)
    // =========================================================================
    public function pushNotebooks(Request $request)
    {
        $user = $request->user();
        $syncedNotebooks = [];

        foreach ($request->input('notebooks', []) as $data) {
            $incomingTime = (int)($data['updated_at'] ?? 0);

            // 🎯 BUSCA HÍBRIDA
            $notebook = null;
            if (!empty($data['server_id'])) {
                $notebook = Notebook::withTrashed()->find($data['server_id']);
            }
            if (!$notebook) {
                $notebook = Notebook::withTrashed()->where('client_id', $data['client_id'])->first();
            }

            // 🛡️ VALIDAÇÃO DE ROLE: Apenas dono ou editor pode dar push no caderno (metadados)
            if ($notebook) {
                $userRole = 'student';
                if ($notebook->subject && $notebook->subject->user_id === $user->id) {
                    $userRole = 'owner';
                } else {
                    $pivot = DB::table('notebook_user')->where('notebook_id', $notebook->id)->where('user_id', $user->id)->first();
                    $userRole = $pivot ? $pivot->role : 'viewer';
                }
                if ($userRole === 'viewer' || $userRole === 'student') {
                    Log::warning("⚠️ [Sync] Tentativa de push metadados do caderno {$notebook->id} por utilizador {$user->id} ($userRole) negada.");
                    continue;
                }
            }

            // 🗑️ Deleção LWW
            if (!empty($data['is_deleted']) && $data['is_deleted'] == 1) {
                if ($notebook && !$notebook->trashed()) {
                    if ($notebook->subject && $notebook->subject->user_id == $user->id) {
                        if ($incomingTime > ($notebook->updated_at_ms ?? 0)) {
                            $notebook->update(['updated_at_ms' => $incomingTime]);

                            // 🚀 Notificar colaboradores
                            try {
                                NotebookDeleted::dispatch($notebook);
                            } catch (\Exception $e) {}

                            $notebook->delete();
                        }
                    }
                }
                continue;
            }

            $updateData = [
                'client_id'  => $data['client_id'],
                'subject_id' => $data['subject_id'],
                'title'      => $data['title'] ?? '',
                'color'      => $data['color'] ?? null, // 🚀 Persistir cor do caderno
                'template_type' => $data['template_type'] ?? 'study',
                'collaboration_mode' => $data['collaboration_mode'] ?? 'study_group',
                'line_type'  => $data['line_type'] ?? 'ruled',
                'paper_size' => $data['paper_size'] ?? 'A4',
                'updated_at_ms' => $incomingTime,
            ];

            if ($notebook) {
                // 🚀 LWW
                if ($incomingTime >= ($notebook->updated_at_ms ?? 0)) {
                    if ($notebook->trashed()) $notebook->restore();
                    $notebook->update($updateData);
                }
            } else {
                $notebook = Notebook::create($updateData);
            }

            $syncedNotebooks[] = ['client_id' => $data['client_id'], 'server_id' => $notebook->id];
        }

        return response()->json(['message' => 'OK', 'synced_notebooks' => $syncedNotebooks]);
    }


    public function pullNotebooks(Request $request)
    {
        $user = $request->user();
        $lastSyncedAt = $request->query('last_synced_at');

        // Unifica a busca por cadernos próprios e partilhados numa única query paginável,
        // preservando a lógica original.
        $query = Notebook::withTrashed()->where(function ($q) use ($user) {
            // Condição 1: Cadernos onde o utilizador é o dono (através da disciplina)
            $q->whereHas('subject', fn($sub) => $sub->where('user_id', $user->id))
              // OU Condição 2: Cadernos que foram partilhados com o utilizador
              ->orWhereHas('sharedUsers', fn($shared) => $shared->where('user_id', $user->id));
        });

        // Aplica o filtro de sincronização, se existir
        if ($lastSyncedAt) {
            $query->where('updated_at', '>', $lastSyncedAt);
        }

        // Pagina o resultado em vez de buscar tudo com ->get()
        $paginatedNotebooks = $query->paginate(50);

        // 🚀 INJEÇÃO DE ROLE: Identificar o papel do utilizador em cada caderno para o App Flutter
        $items = collect($paginatedNotebooks->items())->map(function ($notebook) use ($user) {
            $role = 'viewer';

            // 1. Se é o dono (através da disciplina)
            if ($notebook->subject && $notebook->subject->user_id === $user->id) {
                $role = 'owner';
            } else {
                // 2. Se é um colaborador, buscar a role na tabela pivot
                $pivot = DB::table('notebook_user')
                    ->where('notebook_id', $notebook->id)
                    ->where('user_id', $user->id)
                    ->first();
                $role = $pivot ? $pivot->role : 'viewer';
            }

            // Converter para array e injetar a role
            $data = $notebook->toArray();
            $data['role'] = $role;

            // 🚀 INJETAR AUTOR REAL: Se for partilhado, garantir que o nome do dono aparece
            if ($role !== 'owner' && $notebook->subject && $notebook->subject->user) {
                $data['author_name'] = $notebook->subject->user->name;
            }

            // 🚀 PRIVACIDADE & ESTADO LIVE: Buscar sessão ativa para metadados dinâmicos
            $session = CollaborativeSession::where('notebook_id', $notebook->id)
                ->where('is_active', true)
                ->first();

            if ($session) {
                $data['alternative_title'] = $session->alternative_title;
                $data['sharing_type'] = $session->sharing_type;
            } else {
                $data['alternative_title'] = null;
                $data['sharing_type'] = 'full';
            }

            return $data;
        });

        return response()->json([
            'data' => $items,
            'links' => $paginatedNotebooks->linkCollection(),
            'meta' => [
                'server_time' => now()->format('Y-m-d\TH:i:s\Z'),
                'server_time_ms' => (int)(microtime(true) * 1000)
            ]
        ]);
    }

    // =========================================================================
    // ✍️ 3. SINCRONIZAÇÃO DE PÁGINAS (PRESERVA IMAGENS BASE64, STROKES E TEXT_DATA)
    // =========================================================================
    public function pushPages(Request $request)
    {
        $user = $request->user();
        $clientPages = $request->input('pages', []);
        $syncedPages = [];

        DB::transaction(function () use ($user, $clientPages, &$syncedPages) {
            foreach ($clientPages as $pageData) {
                $result = $this->syncService->processPageData($pageData, $user);
                if ($result) {
                    $syncedPages[] = $result;
                }
            }
        });

        return response()->json(['message' => 'OK', 'synced_pages' => $syncedPages]);
    }


    public function pullPages(Request $request)
    {
        $user = $request->user();
        $lastSyncedAt = $request->query('last_synced_at');
        $notebookId = $request->query('notebook_id');
        $pageNumber = $request->query('page_number');

        // 🚀 FILTRAGEM POR SESSÃO (WHITELIST)
        // Se o utilizador não for o dono do caderno, ele só pode puxar páginas vinculadas a uma sessão ativa
        $isOwner = false;
        if ($notebookId) {
            $notebook = Notebook::find($notebookId);
            $isOwner = $notebook && $notebook->subject && $notebook->subject->user_id === $user->id;
        }

        $query = Page::withTrashed()->whereHas('notebook', function ($q) use ($user) {
            $q->where(function ($inner) use ($user) {
                $inner->whereHas('subject', function ($sub) use ($user) {
                    $sub->where('user_id', $user->id);
                })->orWhereHas('sharedUsers', function ($sub) use ($user) {
                    $sub->where('user_id', $user->id);
                });
            });
        });

        if ($notebookId) {
            $query->where('notebook_id', $notebookId);

            if (!$isOwner) {
                $session = CollaborativeSession::where('notebook_id', $notebookId)->where('is_active', true)->first();
                if ($session && $session->sharing_type === 'scoped') {
                    $sharedPageIds = CollaborativeSessionPage::where('session_id', $session->id)->pluck('page_id');
                    $query->whereIn('id', $sharedPageIds);
                }
            }
        }

        if ($pageNumber) {
            $query->where('page_number', $pageNumber);
        }

        if ($lastSyncedAt) { $query->where('updated_at', '>', $lastSyncedAt); }

        // 🚀 ORDENAÇÃO DETERMINÍSTICA: Garante que os "chunks" de dados cheguem numa ordem
        // que facilite a reconciliação e evite saltos de página.
        $query->orderBy('page_number')->orderBy('client_id');

        // Em vez de ->get(), usamos ->paginate() para enviar os dados em "chunks"
        $paginatedPages = $query->paginate(50);

        return response()->json([
            'data' => $paginatedPages->items(),
            'meta' => [
                'server_time' => now()->format('Y-m-d\TH:i:s\Z'),
                'server_time_ms' => (int)(microtime(true) * 1000)
            ],
            'links' => $paginatedPages->linkCollection(),
        ]);
    }

    // =========================================================================
    // 🎙️ 4. SINCRONIZAÇÃO DE GRAVAÇÕES DE AULA
    // =========================================================================
    public function pushRecordings(Request $request)
    {
        $user = $request->user();
        $recordings = $request->input('recordings', []);
        $synced = [];

        foreach ($recordings as $data) {
            if (empty($data['client_id'])) continue;

            $recording = LessonRecording::withTrashed()->where('client_id', $data['client_id'])->first();

            if ($recording && !empty($data['is_deleted']) && $data['is_deleted'] == 1) {
                $recording->delete();
                continue;
            }

            if (!$recording) {
                $recording = LessonRecording::create([
                    'notebook_id' => $data['notebook_id'],
                    'client_id' => $data['client_id'],
                    'title' => $data['title'],
                    'audio_url' => $data['audio_url'],
                    'duration_seconds' => $data['duration_seconds'] ?? 0,
                    'updated_at_ms' => $data['updated_at'] ?? round(microtime(true) * 1000),
                ]);
            } else {
                $incomingTime = (int)($data['updated_at'] ?? 0);
                if ($incomingTime > ($recording->updated_at_ms ?? 0)) {
                    $recording->update([
                        'title' => $data['title'],
                        'duration_seconds' => $data['duration_seconds'],
                        'updated_at_ms' => $incomingTime,
                    ]);
                }
            }
            $synced[] = ['client_id' => $data['client_id'], 'server_id' => $recording->id];
        }

        return response()->json(['message' => 'OK', 'synced_recordings' => $synced]);
    }

    public function pullRecordings(Request $request)
    {
        $user = $request->user();
        $lastSyncedAt = $request->query('last_synced_at');

        $query = LessonRecording::whereHas('notebook', function ($q) use ($user) {
            $q->whereHas('subject', function ($sub) use ($user) {
                $sub->where('user_id', $user->id);
            })->orWhereHas('sharedUsers', function ($sub) use ($user) {
                $sub->where('user_id', $user->id);
            });
        });

        if ($lastSyncedAt) {
            $query->where('updated_at', '>', $lastSyncedAt);
        }

        return response()->json([
            'data' => $query->get(),
            'meta' => [
                'server_time' => now()->format('Y-m-d\TH:i:s\Z'),
                'server_time_ms' => (int)(microtime(true) * 1000)
            ]
        ]);
    }

    /**
     * 🚀 UPDATE HÍBRIDO (REDIS + JOB)
     * Recebe dados em tempo real (end of touch) e processa via Redis.
     */
    public function realtimeUpdate(Request $request)
    {
        $user = $request->user();
        $pageData = $request->input('page');

        if (empty($pageData['client_id'])) {
            return response()->json(['error' => 'client_id missing'], 400);
        }

        $clientId = $pageData['client_id'];
        $notebookId = $pageData['notebook_id'];

        // 🛡️ Validação rápida de permissão
        $notebook = Notebook::find($notebookId);
        if (!$notebook) return response()->json(['error' => 'notebook not found'], 404);

        $userRole = 'student';
        if ($notebook->subject && $notebook->subject->user_id === $user->id) {
            $userRole = 'owner';
        } else {
            $pivot = DB::table('notebook_user')->where('notebook_id', $notebook->id)->where('user_id', $user->id)->first();
            $userRole = $pivot ? $pivot->role : 'viewer';
        }

        if ($userRole === 'viewer') {
            return response()->json(['error' => 'forbidden'], 403);
        }

        // 🚀 DESPACHAR JOB IMEDIATAMENTE COM OS DADOS (Atómico)
        // Não usamos mais o Cache::get/put aqui para evitar race conditions.
        // O Job agora recebe o conteúdo completo do toque atual.
        \App\Jobs\ProcessRealtimeUpdate::dispatch($pageData, $user->id, $userRole);

        return response()->json(['status' => 'queued', 'client_id' => $clientId]);
    }
}
