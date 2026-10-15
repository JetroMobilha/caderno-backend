<?php

namespace App\Services;

use App\Models\Page;
use App\Models\Notebook;
use App\Models\User;
use App\Jobs\ProcessPageOcr;
use App\Events\PageUpdated;
use App\Events\PageDeleted;
use App\Events\NotebookStructureUpdated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SyncService
{
    public function processPageData(array $pageData, User $user, ?string $userRole = null): ?array
    {
        if (empty($pageData['client_id'])) return null;

        $localPage = Page::withTrashed()
            ->whereHas('notebook', function ($q) use ($user) {
                $q->whereHas('subject', fn($sub) => $sub->where('user_id', $user->id))
                  ->orWhereHas('sharedUsers', fn($shared) => $shared->where('user_id', $user->id));
            })
            ->where('client_id', $pageData['client_id'])->first();

        $notebookId = $pageData['notebook_id'] ?? ($localPage ? $localPage->notebook_id : null);
        if (!$notebookId) {
            return ['client_id' => $pageData['client_id'], 'status' => 'ignored_no_notebook'];
        }

        $notebook = Notebook::find($notebookId);
        if (!$notebook) {
            return ['client_id' => $pageData['client_id'], 'status' => 'ignored_no_notebook'];
        }

        if (!$userRole) {
            $isOwner = ($notebook->subject && $notebook->subject->user_id === $user->id);
            $pivot = DB::table('notebook_user')->where('notebook_id', $notebook->id)->where('user_id', $user->id)->first();
            $userRole = $isOwner ? 'owner' : ($pivot->role ?? 'viewer');
        }

        if ($userRole === 'viewer') {
            return ['client_id' => $pageData['client_id'], 'status' => 'ignored_no_permission'];
        }

        $incomingTime = (int)($pageData['updated_at'] ?? 0);
        $localTime = $localPage ? ($localPage->updated_at_ms ?? 0) : 0;

        // Determinar se devemos atualizar metadados (se for mais recente, igual ou novo)
        $shouldUpdateMeta = ($incomingTime >= $localTime || !$localPage);

        $updateData = [
            'notebook_id'   => $notebook->id,
            'page_number'   => $pageData['page_number'] ?? ($localPage ? $localPage->page_number : 1),
            'updated_at_ms' => max($incomingTime, $localTime),
            'updated_at'    => now(),
        ];

        if ($shouldUpdateMeta) {
            $updateData['paper_size']    = $pageData['paper_size'] ?? ($localPage ? $localPage->paper_size : 'A4');
            $updateData['is_landscape']  = isset($pageData['is_landscape']) ? (int)$pageData['is_landscape'] : ($localPage ? $localPage->is_landscape : 0);
            $updateData['is_frozen']     = isset($pageData['is_frozen']) ? (int)$pageData['is_frozen'] : ($localPage ? $localPage->is_frozen : 0);
            $updateData['is_infinite']   = isset($pageData['is_infinite']) ? (int)$pageData['is_infinite'] : ($localPage ? $localPage->is_infinite : 0); // 🚀 v29
            $updateData['is_favorite']   = isset($pageData['is_favorite']) ? (int)$pageData['is_favorite'] : ($localPage ? $localPage->is_favorite : 0); // 🚀 v29

            $updateData['line_type']     = array_key_exists('line_type', $pageData) ? $pageData['line_type'] : ($localPage ? $localPage->line_type : null);
            $updateData['line_spacing']  = array_key_exists('line_spacing', $pageData) ? $pageData['line_spacing'] : ($localPage ? $localPage->line_spacing : null);
            $updateData['header_data']   = array_key_exists('header_data', $pageData) ? $pageData['header_data'] : ($localPage ? $localPage->header_data : null);
            $updateData['footer_data']   = array_key_exists('footer_data', $pageData) ? $pageData['footer_data'] : ($localPage ? $localPage->footer_data : null);
            $updateData['extracted_text'] = array_key_exists('extracted_text', $pageData) ? $pageData['extracted_text'] : ($localPage ? $localPage->extracted_text : null);

            // Aceitar nulos para permitir remoção do fundo
            $bgPath = array_key_exists('background_pdf_path', $pageData) ? $pageData['background_pdf_path'] : (array_key_exists('background_image_path', $pageData) ? $pageData['background_image_path'] : ($localPage ? $localPage->background_image_path : null));
            $updateData['background_image_path'] = $bgPath;

            $updateData['background_config'] = array_key_exists('background_config', $pageData) ? $pageData['background_config'] : ($localPage ? $localPage->background_config : null);
            $updateData['viewport_matrix'] = array_key_exists('viewport_matrix', $pageData) ? $pageData['viewport_matrix'] : ($localPage ? $localPage->viewport_matrix : null);
            $updateData['layers'] = array_key_exists('layers', $pageData) ? $pageData['layers'] : ($localPage ? $localPage->layers : null);
        }

        // 🧬 MERGE UNIFICADO (v29+)
        $incomingObjects = $pageData['objects_data'] ?? $this->convertLegacyToObjects($pageData);
        $localObjects = $localPage->objects_data ?? $this->convertLegacyToObjects($localPage?->toArray() ?? []);

        // Processar Base64 de Imagens antes do Merge
        $processedIncoming = $this->processBase64Objects($incomingObjects);

        // Extrair owner e política de proteção
        $notebookOwnerId = $notebook->subject ? $notebook->subject->user_id : null;
        $config = is_string($notebook->configuration) ? json_decode($notebook->configuration, true) : (is_array($notebook->configuration) ? $notebook->configuration : []);
        $protectOwnerContent = $config['protect_owner_content'] ?? false;

        // Realizar o Merge (Last Write Wins por ID de objeto)
        $mergedObjects = Page::mergeObjects($localObjects, $processedIncoming, $user->id, $userRole, $notebookOwnerId, $protectOwnerContent);

        // Simplificar traços antes de salvar
        $updateData['objects_data'] = Page::simplifyUnifiedObjects($mergedObjects);

        if ($localPage) {
            if ($localPage->trashed() && empty($pageData['is_deleted'])) $localPage->restore();
            $localPage->update($updateData);
        } else {
            $updateData['client_id'] = $pageData['client_id'];
            $localPage = Page::create($updateData);
        }

        if (!empty($pageData['is_deleted']) && $pageData['is_deleted'] == 1) {
            if (!$localPage->trashed()) {
                $localPage->delete();
                try { PageDeleted::dispatch($localPage); } catch (\Exception $e) {}
            }
        }

        // 🚀 GATILHO DE OCR
        $hasStrokes = collect($updateData['objects_data'])->contains(fn($o) => ($o['type'] ?? '') === 'stroke');
        if ($hasStrokes && empty($localPage->extracted_text)) {
            try { ProcessPageOcr::dispatch($localPage->id); } catch (\Exception $e) {}
        }

        // Notificações Realtime
        try {
            PageUpdated::dispatch($localPage);
            $this->broadcastStructureUpdate($notebook);

            // 🚀 Disparar SyncRequested para a conta do utilizador (outros dispositivos)
            \App\Events\SyncRequested::dispatch($user->id);

            // 🚀 Se for um caderno partilhado, notificar também o dono e colaboradores
            if ($notebook->subject && $notebook->subject->user_id !== $user->id) {
                \App\Events\SyncRequested::dispatch($notebook->subject->user_id);
            }
            $sharedUsers = DB::table('notebook_user')->where('notebook_id', $notebook->id)->pluck('user_id');
            foreach ($sharedUsers as $sUid) {
                if ($sUid != $user->id) {
                    \App\Events\SyncRequested::dispatch($sUid);
                }
            }
        } catch (\Exception $e) {}

        $result = $localPage->toArray();
        $result['is_deleted'] = $localPage->trashed() ? 1 : 0;
        $result['_sync_status'] = ($incomingTime >= $localTime) ? 'already_current' : 'merged';

        return $result;
    }

    private function processBase64Objects(array $objects): array
    {
        $processed = [];
        foreach ($objects as $obj) {
            if (($obj['type'] ?? '') === 'image' && !empty($obj['image_base64'])) {
                try {
                    $decoded = base64_decode($obj['image_base64']);
                    $filename = 'img_' . Str::random(10) . '_' . time() . '.png';
                    Storage::disk('public')->put('notebook_images/' . $filename, $decoded);
                    $obj['image_path'] = asset('storage/notebook_images/' . $filename);
                    unset($obj['image_base64']);
                } catch (\Exception $e) {
                    Log::error("🚨 [Sync] Erro ao processar Base64 de imagem: " . $e->getMessage());
                }
            }
            $processed[] = $obj;
        }
        return $processed;
    }

    private function convertLegacyToObjects(array $data): array {
        $objs = [];
        if (!empty($data['stroke_data'])) foreach($data['stroke_data'] as $s) { $s['type'] = 'stroke'; $objs[] = $s; }
        if (!empty($data['text_data'])) foreach($data['text_data'] as $t) { $t['type'] = 'text'; $objs[] = $t; }
        if (!empty($data['image_data'])) foreach($data['image_data'] as $i) { $i['type'] = 'image'; $objs[] = $i; }
        return $objs;
    }

    public function broadcastStructureUpdate(Notebook $notebook)
    {
        try {
            $structure = Page::where('notebook_id', $notebook->id)
                ->orderBy('page_number')
                ->orderBy('client_id')
                ->get()
                ->map(function($p) {
                    return [
                        'id' => $p->id,
                        'client_id' => $p->client_id,
                        'page_number' => $p->page_number,
                        'updated_at_ms' => $p->updated_at_ms,
                        'paper_size' => $p->paper_size,
                        'background_config' => $p->background_config,
                        'section_color' => $p->header_data['section_color'] ?? null,
                    ];
                })
                ->toArray();

            NotebookStructureUpdated::dispatch($notebook, $structure);
        } catch (\Exception $e) {
            Log::error("🚨 [Sync] Falha ao disparar NotebookStructureUpdated: " . $e->getMessage());
        }
    }
}
