<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
                ['user_id' => $user->id, 'id' => $subjectData['server_id'] ?? null],
                [
                    'name'  => trim($subjectData['name']),
                    'color' => $subjectData['color'],
                    'icon'  => $subjectData['icon'],
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
                    // Só apaga se for o dono
                    if ($notebook && $notebook->subject->user_id == $user->id) $notebook->delete();
                }
                continue;
            }

            $notebook = Notebook::updateOrCreate(
                ['id' => $notebookData['server_id'] ?? null],
                [
                    'subject_id'  => $notebookData['subject_id'],
                    'title'       => trim($notebookData['title']),
                    'cover_type'  => $notebookData['cover_type'] ?? 'color',
                    'color'       => $notebookData['color'],
                    'line_type'   => $notebookData['line_type'],
                    'paper_size'  => $notebookData['paper_size'] ?? 'A4',
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
   public function pushPages(Request $request) {
        $user = $request->user();
        $clientPages = $request->input('pages', []);
        $syncedPages = [];

        foreach ($clientPages as $pageData) {
            $page = Page::where('notebook_id', $pageData['notebook_id'])
                        ->where('page_number', $pageData['page_number'])
                        ->first() ?? new Page(['notebook_id' => $pageData['notebook_id'], 'page_number' => $pageData['page_number']]);

            // 🧠 FUSÃO INTELIGENTE DE DADOS
            $newStrokes = is_string($pageData['stroke_data'] ?? []) ? json_decode($pageData['stroke_data'], true) : ($pageData['stroke_data'] ?? []);
            $page->stroke_data = json_encode(Page::mergeJsonItems($page->stroke_data, $newStrokes));

            $newTexts = is_string($pageData['text_data'] ?? []) ? json_decode($pageData['text_data'], true) : ($pageData['text_data'] ?? []);
            $page->text_data = json_encode(Page::mergeJsonItems($page->text_data, $newTexts));

            // Imagens com Base64
            $incomingImages = is_string($pageData['image_data'] ?? []) ? json_decode($pageData['image_data'], true) : ($pageData['image_data'] ?? []);
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
            $page->image_data = json_encode(Page::mergeJsonItems($page->image_data, $processedImages));

            $page->is_landscape = $pageData['is_landscape'] ?? $page->is_landscape;
            $page->header_data = $pageData['header_data'] ?? $page->header_data;
            $page->save();

            $syncedPages[] = ['client_id' => $pageData['client_id'] ?? null, 'server_id' => $page->id, 'page_number' => $page->page_number];
        }

        SyncRequested::dispatch($user->id);
        return response()->json(['message' => 'Páginas salvas.', 'synced_pages' => $syncedPages]);
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