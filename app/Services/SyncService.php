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

        // 🚀 PERMITIR MOVIMENTAÇÃO ENTRE CADERNOS
        if ($localPage && $localPage->notebook_id != $notebookId) {
            Log::info("📦 [Sync] Movendo folha {$pageData['client_id']} do caderno {$localPage->notebook_id} para $notebookId");
        }

        $notebook = Notebook::find($notebookId);
        if (!$notebook) return null;

        // Resolução de Role
        if (!$userRole) {
            if ($notebook->subject && $notebook->subject->user_id === $user->id) {
                $userRole = 'owner';
            } else {
                $pivot = DB::table('notebook_user')->where('notebook_id', $notebook->id)->where('user_id', $user->id)->first();
                $userRole = $pivot ? $pivot->role : 'viewer';
            }
        }

        if ($userRole === 'viewer') return null;

        // 🕒 LÓGICA DE TEMPO
        $incomingTime = (int)($pageData['updated_at'] ?? 0);
        $localTime = $localPage ? ($localPage->updated_at_ms ?? 0) : 0;
        $shouldUpdateMetadata = ($incomingTime > $localTime);

        $updateData = [
            'notebook_id'   => $notebook->id,
            'page_number'   => $pageData['page_number'] ?? ($localPage ? $localPage->page_number : 1),
            'updated_at'    => now(),
            'updated_at_ms' => max($incomingTime, $localTime),
            'deleted_at'    => null, // Reset temporário para processamento de dados
        ];

        if ($shouldUpdateMetadata || !$localPage) {
            if ($localPage && $localPage->page_number != ($pageData['page_number'] ?? $localPage->page_number)) {
                Log::info("🔄 [Sync] Reordenação detectada para folha {$localPage->client_id}: {$localPage->page_number} -> {$pageData['page_number']}");
            }
            $updateData['is_landscape'] = !empty($pageData['is_landscape']) ? 1 : 0;
            $updateData['is_frozen']    = !empty($pageData['is_frozen']) ? 1 : 0;
            $updateData['paper_size']   = $pageData['paper_size'] ?? 'A4';
            $updateData['line_type']    = $pageData['line_type'] ?? ($localPage ? $localPage->line_type : null);
            $updateData['line_spacing'] = $pageData['line_spacing'] ?? ($localPage ? $localPage->line_spacing : null);
            $updateData['header_data']  = $pageData['header_data'] ?? ($localPage ? $localPage->header_data : ['title' => '']);
            $updateData['footer_data']  = $pageData['footer_data'] ?? ($localPage ? $localPage->footer_data : ['title' => '']);
            $updateData['background_config'] = $pageData['background_config'] ?? ($localPage ? $localPage->background_config : null);
            $updateData['extracted_text'] = $pageData['extracted_text'] ?? ($localPage ? $localPage->extracted_text : null);
        }

        // 🧬 MERGE DE DADOS (Strokes, Text, Images)
        // Fazemos o merge mesmo que esteja deletado para não perder conteúdo
        $rawStrokes = Page::mergeJsonItems($localPage->stroke_data ?? [], $this->parseClientArray($pageData['stroke_data'] ?? []), $user->id, $userRole);
        $updateData['stroke_data'] = Page::simplifyStrokes($rawStrokes);
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

        // 💾 PERSISTÊNCIA E ESTADO DE DELEÇÃO
        if ($localPage) {
            if ($localPage->trashed() && !$isDeletion) {
                Log::info("♻️ [Sync] Restaurando folha {$localPage->client_id}");
                $localPage->restore();
                $isCreation = true;
            }
            $localPage->update($updateData);
        } else {
            $updateData['client_id'] = $pageData['client_id'];
            $localPage = Page::create($updateData);
            $isCreation = true;
            Log::info("🆕 [Sync] Criada folha: {$localPage->client_id}");
        }

        if ($isDeletion) {
            if (!$localPage->trashed()) {
                Log::info("💀 [Sync] Movendo folha para a lixeira (com dados salvos): {$localPage->client_id}");
                $localPage->delete();
                try { PageDeleted::dispatch($localPage); } catch (\Exception $e) {}
            }
        }

        // 🚀 Forçar atualização estrutural se o cliente sinalizar
        if (!empty($pageData['is_new_page'])) {
            $isCreation = true;
        }

        // OCR assíncrono
        if (!empty($pageData['stroke_data']) && empty($localPage->extracted_text)) {
            try { ProcessPageOcr::dispatch($localPage->id); } catch (\Exception $e) {}
        }

        // Notificações Realtime
        try {
            PageUpdated::dispatch($localPage);
            if ($isCreation || $isDeletion) {
                $this->broadcastStructureUpdate($notebook);
            }
        } catch (\Exception $e) {}

        // 📤 RETORNO
        $result = $localPage->toArray();
        $result['is_deleted'] = $localPage->trashed() ? 1 : 0;
        $result['status'] = $isDeletion ? 'deleted' : 'active';

        if ($incomingTime >= ($localPage->updated_at_ms ?? 0)) {
            unset($result['stroke_data'], $result['text_data'], $result['image_data']);
            $result['_sync_status'] = 'already_current';
        } else {
            $result['_sync_status'] = 'merged_and_updated';
        }

        return $result;
    }

    public function broadcastStructureUpdate(Notebook $notebook)
    {
        try {
            $session = CollaborativeSession::where('notebook_id', $notebook->id)
                ->where('is_active', true)
                ->orderBy('started_at', 'desc')
                ->first();

            $query = Page::where('notebook_id', $notebook->id);

            if ($session && $session->sharing_type === 'scoped') {
                $sharedPageIds = CollaborativeSessionPage::where('session_id', $session->id)->pluck('page_id');
                $query->whereIn('id', $sharedPageIds);
            }

            $structure = $query->orderBy('page_number')
                ->orderBy('client_id')
                ->get()
                ->map(function($p) {
                    return [
                        'id' => $p->id,
                        'client_id' => $p->client_id,
                        'page_number' => $p->page_number,
                        'updated_at_ms' => $p->updated_at_ms,
                        'line_type' => $p->line_type,
                        'line_spacing' => $p->line_spacing,
                        'background_config' => $p->background_config,
                        'fingerprint' => $p->generateFingerprint(),
                        'section_color' => $p->header_data['section_color'] ?? null,
                    ];
                })
                ->toArray();

            NotebookStructureUpdated::dispatch($notebook, $structure, $session ? $session->alternative_title : null, $session ? $session->sharing_type : 'full');
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
