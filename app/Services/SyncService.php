<?php

namespace App\Services;

use App\Models\CollaborativeSession;
use App\Models\CollaborativeSessionPage;
use App\Models\Page;
use App\Models\Notebook;
use App\Models\User;
use App\Events\PageDeleted;
use App\Events\PageUpdated;
use App\Events\NotebookStructureUpdated;
use App\Jobs\ProcessPageOcr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SyncService
{
    /**
     * Processa a atualização ou criação de uma única página.
     */
    public function processPageData(array $pageData, User $user, ?string $userRole = null): ?array
    {
        if (empty($pageData['client_id'])) return null;

        $isCreation = false;
        $isDeletion = !empty($pageData['is_deleted']) && $pageData['is_deleted'] == 1;

        // 🛡️ SEGURANÇA: Filtrar apenas folhas de cadernos aos quais o utilizador tem acesso
        $localPage = Page::withTrashed()
            ->whereHas('notebook', function ($q) use ($user) {
                $q->whereHas('subject', fn($sub) => $sub->where('user_id', $user->id))
                  ->orWhereHas('sharedUsers', fn($shared) => $shared->where('user_id', $user->id));
            })
            ->where('client_id', $pageData['client_id'])
            ->first();
        $notebookId = $pageData['notebook_id'] ?? ($localPage ? $localPage->notebook_id : null);

        if (!$notebookId) return null;

        if ($localPage && $localPage->notebook_id != $notebookId) {
            Log::warning("⚠️ [Sync] Tentativa de mover folha {$pageData['client_id']} do caderno {$localPage->notebook_id} para $notebookId abortada.");
            return ['client_id' => $pageData['client_id'], 'status' => 'conflict_notebook_mismatch'];
        }

        $notebook = Notebook::find($notebookId);
        if (!$notebook) return null;

        // Se a role não foi passada, calculamos agora
        if (!$userRole) {
            if ($notebook->subject && $notebook->subject->user_id === $user->id) {
                $userRole = 'owner';
            } else {
                $pivot = DB::table('notebook_user')->where('notebook_id', $notebook->id)->where('user_id', $user->id)->first();
                $userRole = $pivot ? $pivot->role : 'viewer';
            }
        }

        if ($userRole === 'viewer') return null;

        // Deleção
        if (!empty($pageData['is_deleted']) && $pageData['is_deleted'] == 1) {
            if ($localPage && !$localPage->trashed()) {
                $localPage->update(['updated_at_ms' => $pageData['updated_at'] ?? null]);
                $localPage->delete();
                try { PageDeleted::dispatch($localPage); } catch (\Exception $e) {}
            }
            return ['client_id' => $pageData['client_id'], 'server_id' => $localPage?->id, 'status' => 'deleted'];
        }

        // 🚀 LÓGICA DE ATUALIZAÇÃO GRANULAR:
        // Não ignoramos mais o pacote inteiro pelo timestamp da página (ignored_old).
        // Deixamos o mergeJsonItems decidir traço a traço o que é mais recente.
        $incomingTime = (int)($pageData['updated_at'] ?? 0);
        $localTime = $localPage ? ($localPage->updated_at_ms ?? 0) : 0;

        $shouldUpdateMetadata = ($incomingTime > $localTime);

        $updateData = [
            'notebook_id'   => $notebook->id,
            'page_number'   => $pageData['page_number'] ?? ($localPage ? $localPage->page_number : 1),
            'updated_at'    => now(),
            'updated_at_ms' => max($incomingTime, $localTime), // Preserva o maior tempo
            'deleted_at'    => null,
        ];

        // Só atualiza metadados se o cliente for mais recente que o servidor
        if ($shouldUpdateMetadata || !$localPage) {
            $updateData['is_landscape'] = !empty($pageData['is_landscape']) ? 1 : 0;
            $updateData['is_frozen']    = !empty($pageData['is_frozen']) ? 1 : 0;
            $updateData['paper_size']   = $pageData['paper_size'] ?? 'A4';
            $updateData['line_type']    = $pageData['line_type'] ?? ($localPage ? $localPage->line_type : null);
            $updateData['line_spacing'] = $pageData['line_spacing'] ?? ($localPage ? $localPage->line_spacing : null);
            $updateData['header_data']  = $pageData['header_data'] ?? ($localPage ? $localPage->header_data : ['title' => '']);
            $updateData['footer_data']  = $pageData['footer_data'] ?? ($localPage ? $localPage->footer_data : ['title' => '']);
            $updateData['extracted_text'] = $pageData['extracted_text'] ?? ($localPage ? $localPage->extracted_text : null);
        }

        // Merge de sub-items
        $rawStrokes = Page::mergeJsonItems($localPage->stroke_data ?? [], $this->parseClientArray($pageData['stroke_data'] ?? []), $user->id, $userRole);

        // 🚀 OTIMIZAÇÃO NO SERVIDOR: Simplificar traços após o merge para economizar largura de banda para todos
        $updateData['stroke_data'] = Page::simplifyStrokes($rawStrokes);

        $updateData['text_data']   = Page::mergeJsonItems($localPage->text_data ?? [], $this->parseClientArray($pageData['text_data'] ?? []), $user->id, $userRole);

        // Imagens (Lidar com Base64 se houver)
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
            if($localPage->trashed()) {
                $localPage->restore();
                $isCreation = true;
            }
            $localPage->update($updateData);
        } else {
            $updateData['client_id'] = $pageData['client_id'];
            $localPage = Page::create($updateData);
            $isCreation = true;
        }

        // 🚀 Forçar atualização estrutural se o cliente sinalizar
        if (!empty($pageData['is_new_page'])) {
            $isCreation = true;
        }

        // OCR assíncrono se houver traços novos e sem texto
        if (!empty($pageData['stroke_data']) && empty($localPage->extracted_text)) {
            try { ProcessPageOcr::dispatch($localPage->id); } catch (\Exception $e) {}
        }

        // Notificar via Reverb
        try {
            PageUpdated::dispatch($localPage);

            if ($isCreation || $isDeletion) {
                $this->broadcastStructureUpdate($notebook);
            }
        } catch (\Exception $e) {}

        // 🚀 RETORNO AUTORITATIVO INTELIGENTE:
        // Se o cliente já está atualizado (pelo timestamp), não devolvemos o array pesado de traços.
        $clientTime = (int)($pageData['updated_at'] ?? 0);
        $serverTime = $localPage->updated_at_ms ?? 0;

        $result = $localPage->toArray();

        if ($clientTime >= $serverTime) {
            // Cliente já tem a verdade ou enviou a mais recente.
            // Removemos os dados pesados para economizar tráfego na resposta.
            unset($result['stroke_data'], $result['text_data'], $result['image_data']);
            $result['_sync_status'] = 'already_current';
        } else {
            $result['_sync_status'] = 'merged_and_updated';
        }

        Log::info("🛰️ [Sync] Push Delta para folha {$localPage->client_id}. Status: {$result['_sync_status']}");
        return $result;
    }

    public function broadcastStructureUpdate(Notebook $notebook)
    {
        try {
            // 🚀 FILTRAR ESTRUTURA SE HOUVER SESSÃO ATIVA (WHITELIST)
            $session = CollaborativeSession::where('notebook_id', $notebook->id)
                ->where('is_active', true)
                ->orderBy('started_at', 'desc')
                ->first();

            $query = Page::where('notebook_id', $notebook->id);

            if ($session && $session->sharing_type === 'scoped') {
                $sharedPageIds = CollaborativeSessionPage::where('session_id', $session->id)->pluck('page_id');
                $query->whereIn('id', $sharedPageIds);
                Log::info("🔒 [Sync] Estrutura da sessão {$session->id} filtrada.");
            }

            $structure = $query->orderBy('page_number')
                ->orderBy('client_id')
                ->get(['id', 'client_id', 'page_number', 'updated_at_ms', 'is_frozen', 'paper_size', 'line_type', 'line_spacing', 'stroke_data', 'text_data', 'image_data'])
                ->map(function($p) {
                    return [
                        'id' => $p->id,
                        'client_id' => $p->client_id,
                        'page_number' => $p->page_number,
                        'updated_at_ms' => $p->updated_at_ms,
                        'line_type' => $p->line_type,
                        'line_spacing' => $p->line_spacing,
                        'fingerprint' => $p->generateFingerprint(),
                    ];
                })
                ->toArray();

            NotebookStructureUpdated::dispatch(
                $notebook,
                $structure,
                $session ? $session->alternative_title : null,
                $session ? $session->sharing_type : 'full'
            );
        } catch (\Exception $e) {
            Log::error("🚨 [Sync] Falha ao disparar NotebookStructureUpdated: " . $e->getMessage());
        }
    }

    private function parseClientArray($data) {
        if (is_array($data)) return $data;
        if (is_string($data)) return json_decode($data, true) ?? [];
        return [];
    }
}
