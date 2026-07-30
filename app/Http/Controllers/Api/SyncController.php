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

        foreach ($clientSubjects as $subjectData) {
            
            if (!empty($subjectData['is_deleted']) && $subjectData['is_deleted'] == 1) {
                if (!empty($subjectData['server_id'])) {
                    $subject = Subject::where('user_id', $user->id)->where('id', $subjectData['server_id'])->first();
                    if ($subject) $subject->delete();
                }
                $syncedSubjects[] = ['client_id' => $subjectData['id'], 'server_id' => $subjectData['server_id'] ?? null];
                continue;
            }

            $subject = Subject::updateOrCreate(
                [
                    'client_id' => $subjectData['client_id'] // 🆔 Chave única gerada no Flutter
                ],
                [
                    'user_id' => $user->id,
                    'name'    => trim($subjectData['name'] ?? 'Nova Disciplina'),
                    'color'   => $subjectData['color'] ?? '#000000',
                    'icon'    => $subjectData['icon'] ?? 'default-icon',
                ]
            );

            $syncedSubjects[] = ['client_id' => $subjectData['id'], 'server_id' => $subject->id];
        }

        if($syncedSubjects) SyncRequested::dispatch($user->id);

        return response()->json(['message' => 'Disciplinas processadas.', 'synced_subjects' => $syncedSubjects]);
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
        $clientNotebooks = $request->input('notebooks', []);
        $syncedNotebooks = [];

        foreach ($clientNotebooks as $notebookData) {
            
            if (!empty($notebookData['is_deleted']) && $notebookData['is_deleted'] == 1) {
                if (!empty($notebookData['server_id'])) {
                    $notebook = Notebook::where('id', $notebookData['server_id'])->first();
                    if ($notebook && $notebook->subject->user_id == $user->id) $notebook->delete();
                }
                continue;
            }

           
            $notebook = Notebook::updateOrCreate(
                ['client_id' => $notebookData['client_id']], // Chave de busca (UUID do Flutter)
                [
                    'subject_id'  => $notebookData['subject_id'] ?? null,
                    'title'       => !empty($notebookData['title']) ? trim($notebookData['title']) : '', // 🚀 Permite título vazio
                    'cover_type'  => !empty($notebookData['cover_type']) ? $notebookData['cover_type'] : 'color',
                    'color'       => !empty($notebookData['color']) ? $notebookData['color'] : '#3b82f6',
                    'line_type'   => !empty($notebookData['line_type']) ? $notebookData['line_type'] : 'ruled', // 🚀 CORRIGIDO: Flutter usa 'ruled'
                    'paper_size'  => !empty($notebookData['paper_size']) ? $notebookData['paper_size'] : 'A4',
                ]
            );

            $syncedNotebooks[] = ['client_id' => $notebookData['id'], 'server_id' => $notebook->id];
        }

        if($syncedNotebooks) SyncRequested::dispatch($user->id);
        return response()->json(['message' => 'Cadernos processados.', 'synced_notebooks' => $syncedNotebooks]);
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
            if (!empty($newStrokes) && empty($page->extracted_text)) {
                ProcessPageOcr::dispatch($page->id);
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