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
            // 🗑️ Deleção (Igual)
            if (!empty($data['is_deleted']) && $data['is_deleted'] == 1) {
                Subject::where('user_id', $user->id)->where('client_id', $data['client_id'])->delete();
                continue;
            }

            // 🎯 BUSCA HÍBRIDA: Tenta ID do Servidor primeiro, depois o UUID
            $subject = null;
            if (!empty($data['server_id'])) {
                $subject = Subject::where('user_id', $user->id)->find($data['server_id']);
            }
            if (!$subject) {
                $subject = Subject::where('user_id', $user->id)->where('client_id', $data['client_id'])->first();
            }

            // Se encontrou, atualiza. Se não, cria.
            if ($subject) {
                $subject->update([
                    'client_id' => $data['client_id'], // 🚀 Migra o UUID se estiver NULL
                    'name'      => trim($data['name'] ?? $subject->name),
                    'color'     => $data['color'] ?? $subject->color,
                    'icon'      => $data['icon'] ?? $subject->icon,
                ]);
            } else {
                $subject = Subject::create([
                    'user_id'   => $user->id,
                    'client_id' => $data['client_id'],
                    'name'      => trim($data['name'] ?? 'Nova Disciplina'),
                    'color'     => $data['color'] ?? '#000000',
                    'icon'      => $data['icon'] ?? 'default-icon',
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
            // 🗑️ Deleção (Igual)
            if (!empty($data['is_deleted']) && $data['is_deleted'] == 1) {
                $n = Notebook::where('client_id', $data['client_id'])->first();
                if ($n && $n->subject->user_id == $user->id) $n->delete();
                continue;
            }

            // 🎯 BUSCA HÍBRIDA
            $notebook = null;
            if (!empty($data['server_id'])) {
                $notebook = Notebook::find($data['server_id']);
            }
            if (!$notebook) {
                $notebook = Notebook::where('client_id', $data['client_id'])->first();
            }

            $updateData = [
                'client_id'  => $data['client_id'], // 🚀 Migra o UUID
                'subject_id' => $data['subject_id'],
                'title'      => $data['title'] ?? '',
                'line_type'  => $data['line_type'] ?? 'ruled',
                'paper_size' => $data['paper_size'] ?? 'A4',
            ];

            if ($notebook) {
                $notebook->update($updateData);
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

        return response()->json([
            'data' => $paginatedNotebooks->items(),
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
                // 1. Reconciliação por client_id
                $page = Page::lockForUpdate()->firstOrNew(['client_id' => $pageData['client_id']]);

                // 2. Segurança (Roles)
                $notebook = Notebook::find($page->notebook_id ?? $pageData['notebook_id']);
                if (!$notebook) continue;

                $isOwner = $notebook->subject->user_id === $user->id;
                $isEditor = $notebook->sharedUsers()->where('user_id', $user->id)->whereIn('role', ['editor'])->exists();

                if (!$isOwner && !$isEditor) {
                    continue; // Silenciosamente ignora o push de viewers
                }
                
                // 3. Soft Delete
                if (!empty($pageData['is_deleted']) && $pageData['is_deleted'] == 1) {
                    if ($page->exists) {
                        $page->delete();
                        // Disparar evento de deleção
                        broadcast(new SyncRequested('page.deleted', $page, $notebook->id))->toOthers();
                    }
                    continue;
                }

                // Preencher dados para criação ou atualização
                $page->fill([
                    'notebook_id'   => $notebook->id,
                    'page_number'   => $pageData['page_number'],
                    'updated_at_ms' => $pageData['updated_at_ms'] ?? null,
                    'is_landscape'  => !empty($pageData['is_landscape']) ? 1 : 0,
                    'header_data'   => $pageData['header_data'] ?? ['title' => ''],
                    'footer_data'   => $pageData['footer_data'] ?? ['title' => ''],
                    'extracted_text'=> $pageData['extracted_text'] ?? null,
                ]);

                // Fusão de conteúdo JSON com base em Timestamps (LWW)
                $page->stroke_data = Page::mergeJsonItems($page->stroke_data, $this->parseClientArray($pageData['stroke_data'] ?? []));
                $page->text_data   = Page::mergeJsonItems($page->text_data, $this->parseClientArray($pageData['text_data'] ?? []));
                
                // Processamento de Imagens Base64 antes da fusão
                $incomingImages = $this->parseClientArray($pageData['image_data'] ?? []);
                $processedImages = [];
                foreach ($incomingImages as $img) {
                    if (!empty($img['image_base64'])) {
                        $decoded = base64_decode($img['image_base64']);
                        $filename = 'img_' . uniqid() . '.png';
                        Storage::disk('public')->put('notebook_images/' . $filename, $decoded);
                        $img['image_path'] = asset('storage/notebook_images/' . $filename);
                        unset($img['image_base64']);
                    }
                    $processedImages[] = $img;
                }
                $page->image_data = Page::mergeJsonItems($page->image_data, $processedImages);
                
                $page->save();

                // 4. Broadcast
                broadcast(new SyncRequested('page.updated', $page, $notebook->id))->toOthers();

                // Disparar OCR se necessário
                if (!empty($pageData['stroke_data']) && empty($page->extracted_text)) {
                    try {
                        ProcessPageOcr::dispatch($page->id);
                    } catch (\Throwable $e) {
                        Log::error('Erro ao despachar o Job ProcessPageOcr.', ['page_id' => $page->id, 'error' => $e->getMessage()]);
                    }
                }

                $syncedPages[] = [
                    'client_id'   => $page->client_id,
                    'server_id'   => $page->id,
                    'page_number' => $page->page_number
                ];
            }
        });

        return response()->json(['message' => 'Páginas sincronizadas com sucesso.', 'synced_pages' => $syncedPages]);
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

        $query = Page::withTrashed()->whereHas('notebook', function ($q) use ($user) {
            $q->where(function ($inner) use ($user) {
                $inner->whereHas('subject', function ($sub) use ($user) {
                    $sub->where('user_id', $user->id);
                })->orWhereHas('sharedUsers', function ($sub) use ($user) {
                    $sub->where('user_id', $user->id);
                });
            });
        });

        if ($lastSyncedAt) { $query->where('updated_at', '>', $lastSyncedAt); }

        // Em vez de ->get(), usamos ->paginate() para enviar os dados em "chunks"
        $paginatedPages = $query->paginate(50);

        return response()->json([
            'data' => $paginatedPages->items(),
            'meta' => ['server_time' => now()->toIso8601String()],
            'links' => $paginatedPages->linkCollection(),
        ]);
    }
}