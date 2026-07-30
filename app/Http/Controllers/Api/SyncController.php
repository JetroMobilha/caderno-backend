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
        if ($lastSyncedAt) $query->where('updated_at', '>', $lastSyncedAt);

        return response()->json([
            'message' => 'Rastreio de disciplinas concluído.',
            'subjects' => $query->get(),
            'server_time' => now()->toIso8601String()
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

        // 1. Próprios (Respeita deleted_at via Eloquent)
        $own = Notebook::whereHas('subject', fn($q) => $q->where('user_id', $user->id))
                      ->get()->map(fn($n) => (object) array_merge($n->toArray(), ['role' => 'owner']));

        // 2. Partilhados (ADICIONADO whereNull para respeitar a exclusão)
        $shared = DB::table('notebooks')
            ->join('notebook_user', 'notebooks.id', '=', 'notebook_user.notebook_id')
            ->where('notebook_user.user_id', $user->id)
            ->whereNull('notebooks.deleted_at')
            ->select('notebooks.*', 'notebook_user.role')
            ->get()
            ->map(function($n) {
                $n->server_id = $n->id;
                $n->subject_id = null;
                return $n;
            });

        return response()->json([
            'message' => 'Estante universal sincronizada.',
            'notebooks' => $own->concat($shared),
            'server_time' => now()->toIso8601String()
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

        foreach ($clientPages as $pageData) {
            // Localiza ou cria a página
            $page = Page::firstOrNew([
                'notebook_id' => $pageData['notebook_id'],
                'page_number' => $pageData['page_number']
            ]);

            // 1. Metadados de Título (Header/Footer)
            // Como as colunas são JSON, o Laravel trata os arrays automaticamente se houver 'casts' no Model.
            $page->header_data = $pageData['header_data'] ?? ['title' => ''];
            $page->footer_data = $pageData['footer_data'] ?? ['title' => ''];
            $page->is_landscape = !empty($pageData['is_landscape']) ? 1 : 0;

            // 2. Conteúdo (Strokes/Texts)
            $newStrokes = $this->parseClientArray($pageData['stroke_data'] ?? []);
            $page->stroke_data = Page::mergeJsonItems($page->stroke_data, $newStrokes);

            $newTexts = $this->parseClientArray($pageData['text_data'] ?? []);
            $page->text_data = Page::mergeJsonItems($page->text_data, $newTexts);

            // 3. Imagens
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

            $page->extracted_text = $pageData['extracted_text'] ?? null;
            $page->save();

            // Despacha o Job de OCR se houver novos traços e o texto ainda não foi extraído.
            try {
                if (!empty($newStrokes) && empty($page->extracted_text)) {
                    ProcessPageOcr::dispatch($page->id);
                }
            } catch (\Throwable $e) {
                // Protege contra quebra da sincronização e regista no Log
                Log::error('Erro ao despachar o Job ProcessPageOcr.', [
                    'page_id' => $page->id ?? null,
                    'erro' => $e->getMessage(),
                    'linha' => $e->getLine(),
                    'arquivo' => $e->getFile()
                ]);
            }

            $syncedPages[] = [
                'client_id'   => $pageData['client_id'] ?? null,
                'server_id'   => $page->id,
                'page_number' => $page->page_number
            ];
        }

        return response()->json(['message' => 'Páginas sincronizadas com JSON.', 'synced_pages' => $syncedPages]);
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

        $query = Page::whereHas('notebook', function ($q) use ($user) {
            $q->where(function ($inner) use ($user) {
                $inner->whereHas('subject', function ($sub) use ($user) {
                    $sub->where('user_id', $user->id);
                })->orWhereHas('sharedUsers', function ($sub) use ($user) {
                    $sub->where('user_id', $user->id);
                });
            });
        });

        if ($lastSyncedAt) { $query->where('updated_at', '>', $lastSyncedAt); }

        return response()->json([
            'message' => 'Rastreio de páginas concluído.',
            'pages' => $query->get(),
            'server_time' => now()->toIso8601String()
        ]);
    }
}