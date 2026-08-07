<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPageOcr;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Subject;
use App\Models\Notebook;
use App\Models\Page;
use App\Models\LessonRecording;
use App\Events\PageDeleted;
use App\Events\PageUpdated;
use App\Events\SyncRequested;

class SyncController extends Controller
{
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
            'meta' => ['server_time' => now()->toIso8601String()]
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
                'template_type' => $data['template_type'] ?? 'study',
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
            return $data;
        });

        return response()->json([
            'data' => $items,
            'links' => $paginatedNotebooks->linkCollection(),
            'meta' => ['server_time' => now()->toIso8601String()]
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
                if (empty($pageData['client_id'])) continue;

                // 🚀 BUSCA ESTRICTA: O Client ID é a âncora absoluta de identidade.
                $localPage = Page::withTrashed()->where('client_id', $pageData['client_id'])->first();

                $notebookId = $pageData['notebook_id'] ?? ($localPage ? $localPage->notebook_id : null);
                if (!$notebookId) continue;

                // 🛡️ VALIDAÇÃO DE INTEGRIDADE: Uma folha não pode saltar de caderno.
                if ($localPage && $localPage->notebook_id != $notebookId) {
                    Log::warning("⚠️ [Sync] Tentativa de mover folha {$pageData['client_id']} do caderno {$localPage->notebook_id} para $notebookId abortada.");
                    $syncedPages[] = ['client_id' => $pageData['client_id'], 'status' => 'conflict_notebook_mismatch'];
                    continue;
                }

                $notebook = Notebook::find($notebookId);
                if (!$notebook) continue;

                // 🎯 Identificar permissão
                $userRole = 'student';
                if ($notebook->subject && $notebook->subject->user_id === $user->id) {
                    $userRole = 'owner';
                } else {
                    $pivot = DB::table('notebook_user')->where('notebook_id', $notebook->id)->where('user_id', $user->id)->first();
                    $userRole = $pivot ? $pivot->role : 'viewer';
                }

                if ($userRole === 'viewer') continue;

                // 🚀 Lógica LWW Corrigida para milissegundos
                if ($localPage) {
                    $incomingTime = (int)($pageData['updated_at'] ?? 0);
                    $localTime = $localPage->updated_at_ms ?? (strtotime($localPage->updated_at) * 1000);

                    if ($incomingTime <= $localTime) {
                        $syncedPages[] = ['client_id' => $localPage->client_id, 'server_id' => $localPage->id, 'status' => 'ignored_old'];
                        continue;
                    }
                }

                if (!empty($pageData['is_deleted']) && $pageData['is_deleted'] == 1) {
                    if ($localPage && !$localPage->trashed()) {
                        $localPage->update(['updated_at_ms' => $pageData['updated_at'] ?? null]);
                        $localPage->delete();
                    }
                    continue;
                }

                // 🚀 Conversão do Timestamp para o MySQL
                $dbDate = isset($pageData['updated_at'])
                    ? date('Y-m-d H:i:s', (int)($pageData['updated_at'] / 1000))
                    : now();

                $updateData = [
                    'notebook_id'   => $notebook->id,
                    'page_number'   => $pageData['page_number'],
                    'updated_at'    => $dbDate,
                    'updated_at_ms' => $pageData['updated_at'] ?? null, // Guardar precisão ms
                    'is_landscape'  => !empty($pageData['is_landscape']) ? 1 : 0,
                    'is_frozen'     => !empty($pageData['is_frozen']) ? 1 : 0,
                    'header_data'   => $pageData['header_data'] ?? ['title' => ''],
                    'footer_data'   => $pageData['footer_data'] ?? ['title' => ''],
                    'extracted_text'=> $pageData['extracted_text'] ?? null,
                    'deleted_at'    => null,
                ];

                // Sincronizar itens internos
                $updateData['stroke_data'] = Page::mergeJsonItems($localPage->stroke_data ?? [], $this->parseClientArray($pageData['stroke_data'] ?? []), $user->id, $userRole);
                $updateData['text_data']   = Page::mergeJsonItems($localPage->text_data ?? [], $this->parseClientArray($pageData['text_data'] ?? []), $user->id, $userRole);

                // Imagens
                $processedImages = [];
                foreach ($this->parseClientArray($pageData['image_data'] ?? []) as $img) {
                    if (!empty($img['image_base64'])) {
                        $decoded = base64_decode($img['image_base64']);
                        $filename = 'img_' . Str::random(10) . '_' . time() . '.png';
                        Storage::disk('public')->put('notebook_images/' . $filename, $decoded);
                        $img['image_path'] = asset('storage/notebook_images/' . $filename);
                        unset($img['image_base64']);
                    }
                    $processedImages[] = $img;
                }
                $updateData['image_data'] = Page::mergeJsonItems($localPage->image_data ?? [], $processedImages, $user->id, $userRole);

                if ($localPage) {
                    if($localPage->trashed()) $localPage->restore();
                    $localPage->update($updateData);
                } else {
                    $updateData['client_id'] = $pageData['client_id'];
                    $localPage = Page::create($updateData);
                }

                if (!empty($pageData['stroke_data']) && empty($localPage->extracted_text)) {
                    try { ProcessPageOcr::dispatch($localPage->id); } catch (\Exception $e) { Log::error($e->getMessage()); }
                }

                // 🚀 REATIVIDADE: Avisar outros utilizadores que a folha foi atualizada autoritativamente na nuvem
                try {
                    PageUpdated::dispatch($localPage);
                } catch (\Exception $e) {
                    Log::error("🚨 [Sync] Falha ao disparar PageUpdated para folha {$localPage->client_id}: " . $e->getMessage());
                }

                $syncedPages[] = ['client_id' => $localPage->client_id, 'server_id' => $localPage->id, 'page_number' => $localPage->page_number];
            }
        });

        return response()->json(['message' => 'OK', 'synced_pages' => $syncedPages]);
    }

    private function parseClientArray($data) {
        if (is_array($data)) return $data;
        if (is_string($data)) return json_decode($data, true) ?? [];
        return [];
    }

    /**
     * 🛡️ Garante que os dados (Header/Footer) se tornam sempre numa string JSON válida para o MySQL.
     */
    private function normalizeJsonColumn($data, $fallback = []): string
    {
        if (is_null($data) || $data === '') {
            return json_encode($fallback, JSON_UNESCAPED_UNICODE);
        }

        // Se o Laravel já converteu para array ou objeto via Request
        if (is_array($data) || is_object($data)) {
            return json_encode($data, JSON_UNESCAPED_UNICODE);
        }

        if (is_string($data)) {
            // Tenta ver se a string já é um JSON válido
            $decoded = json_decode($data, true);
            if (json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || is_object($decoded))) {
                return json_encode($decoded, JSON_UNESCAPED_UNICODE);
            }
            // Se for texto plano (ex: "Folha 1"), transforma num objeto JSON válido
            return json_encode(['title' => trim($data)], JSON_UNESCAPED_UNICODE);
        }

        return json_encode($fallback, JSON_UNESCAPED_UNICODE);
    }

    public function pullPages(Request $request)
    {
        $user = $request->user();
        $lastSyncedAt = $request->query('last_synced_at');
        $notebookId = $request->query('notebook_id');
        $pageNumber = $request->query('page_number');

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
            'meta' => ['server_time' => now()->toIso8601String()],
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
            'meta' => ['server_time' => now()->toIso8601String()]
        ]);
    }
}
