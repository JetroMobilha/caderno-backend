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
                    if ($subject) { $subject->delete(); }
                }
                $syncedSubjects[] = ['client_id' => $subjectData['id'], 'server_id' => $subjectData['server_id'] ?? null];
                continue;
            }

            $subject = null;
            if (!empty($subjectData['server_id'])) {
                $subject = Subject::where('user_id', $user->id)->where('id', $subjectData['server_id'])->first();
            }

            if (!$subject) {
                $cleanName = trim(strtolower($subjectData['name']));
                $subject = Subject::where('user_id', $user->id)->whereRaw('LOWER(TRIM(name)) = ?', [$cleanName])->first();
            }

            if ($subject) {
                if (!empty($subjectData['server_id'])) {
                    $subject->update([
                        'name'  => trim($subjectData['name']),
                        'color' => $subjectData['color'],
                        'icon'  => $subjectData['icon'],
                    ]);
                }
            } else {
                $subject = Subject::create([
                    'user_id' => $user->id,
                    'name'    => trim($subjectData['name']),
                    'color'   => $subjectData['color'],
                    'icon'    => $subjectData['icon'],
                ]);
            }

            $syncedSubjects[] = ['client_id' => $subjectData['id'], 'server_id' => $subject->id];
        }

        if($syncedSubjects) {
            // ⚡ INTERCONEXÃO EM TEMPO REAL: 
            // Dispara o evento Reverb para avisar todos os outros dispositivos deste utilizador!
            SyncRequested::dispatch($request->user()->id);
        }

        return response()->json(['message' => 'Disciplinas processadas.', 'synced_subjects' => $syncedSubjects]);
    }

    public function pull(Request $request)
    {
        $user = $request->user();
        $lastSyncedAt = $request->query('last_synced_at');
        
        // 🚀 BLINDAGEM DE MULTI-DISPOSITIVOS: withTrashed()
        // Permite que o telemóvel B saiba que o telemóvel A apagou a disciplina!
        $query = Subject::withTrashed()->where('user_id', $user->id);
        
        if ($lastSyncedAt) { 
            $query->where('updated_at', '>', $lastSyncedAt); 
        }

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
                    $notebook = Notebook::where('id', $notebookData['server_id'])
                        ->whereHas('subject', function($q) use ($user) { $q->where('user_id', $user->id); })
                        ->first();
                    if ($notebook) { $notebook->delete(); }
                }
                $syncedNotebooks[] = ['client_id' => $notebookData['id'], 'server_id' => $notebookData['server_id'] ?? null];
                continue;
            }

            $notebook = null;
            $subjectId = $notebookData['subject_id'];

            if (!empty($notebookData['server_id'])) {
                // 🛡️ CAMADA DE SEGURANÇA AUTORIZADA: Só dono ou convidado 'editor' alteram dados
                $isOwner = Notebook::where('id', $notebookData['server_id'])
                    ->whereHas('subject', function($q) use ($user) { $q->where('user_id', $user->id); })
                    ->exists();

                $isSharedEditor = DB::table('notebook_user')
                    ->where('notebook_id', $notebookData['server_id'])
                    ->where('user_id', $user->id)
                    ->where('role', 'editor')
                    ->exists();

                if (!$isOwner && !$isSharedEditor) {
                    Log::warning("🚨 [SEGURANÇA] Bloqueado push ilegal de caderno por {$user->email}");
                    $syncedNotebooks[] = ['client_id' => $notebookData['id'], 'server_id' => $notebookData['server_id']];
                    continue; 
                }

                $notebook = Notebook::find($notebookData['server_id']);
                if ($notebook) { $subjectId = $notebook->subject_id; } 
            }

            if ($notebook) {
                $notebook->update([
                    'title'       => trim($notebookData['title']),
                    'cover_type'  => $notebookData['cover_type'] ?? 'color',
                    'color'       => $notebookData['color'],
                    'cover_image' => $notebookData['cover_image'],
                    'line_type'   => $notebookData['line_type'],
                    'paper_size'  => $notebookData['paper_size'] ?? 'A4',
                ]);
            } else {
                $subjectExists = Subject::where('user_id', $user->id)->where('id', $subjectId)->exists();
                if (!$subjectExists) { continue; }

                $notebook = Notebook::create([
                    'subject_id'  => $subjectId,
                    'title'       => trim($notebookData['title']),
                    'cover_type'  => $notebookData['cover_type'] ?? 'color',
                    'color'       => $notebookData['color'],
                    'cover_image' => $notebookData['cover_image'],
                    'line_type'   => $notebookData['line_type'],
                    'paper_size'  => $notebookData['paper_size'] ?? 'A4',
                ]);
            }

            $syncedNotebooks[] = ['client_id' => $notebookData['id'], 'server_id' => $notebook->id];
        }

         if($syncedNotebooks) {
            // ⚡ INTERCONEXÃO EM TEMPO REAL: 
            // Dispara o evento Reverb para avisar todos os outros dispositivos deste utilizador!
            SyncRequested::dispatch($request->user()->id);
        }
        return response()->json(['message' => 'Cadernos processados.', 'synced_notebooks' => $syncedNotebooks]);
    }

    public function pullNotebooks(Request $request) 
    {
        $user = $request->user();
        $lastSyncedAt = $request->query('last_synced_at');

        // 1. Cadernos Próprios (Injeta papel 'owner')
        $ownNotebooks = Notebook::whereHas('subject', function ($query) use ($user) {
            $query->where('user_id', $user->id);
        })->get()->map(function($notebook) {
            $notebook->role = 'owner';
            return $notebook;
        });

        // 2. Cadernos Partilhados (Lê o papel real e zera a FK de disciplina para o SQLite)
        $sharedNotebooks = DB::table('notebooks')
            ->join('notebook_user', 'notebooks.id', '=', 'notebook_user.notebook_id')
            ->where('notebook_user.user_id', $user->id)
            ->select('notebooks.*', 'notebook_user.role')
            ->get()
            ->map(function($notebook) {
                $notebook->server_id = $notebook->id; 
                $notebook->subject_id = null; // 🟢 HIGIENE RELACIONAL PURA! Rebentámos com o 999999
                return $notebook;
            });

        return response()->json([
            'message' => 'Estante universal sincronizada com sucesso.',
            'notebooks' => $ownNotebooks->concat($sharedNotebooks),
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
            $notebookId = $pageData['notebook_id'];
            $notebook = Notebook::find($notebookId);
            if (!$notebook) continue;

            // Encontrar ou criar a folha
            $page = Page::where('notebook_id', $notebookId)
                        ->where('page_number', $pageData['page_number'])
                        ->first() ?? new Page(['notebook_id' => $notebookId, 'page_number' => $pageData['page_number']]);

            // --- MOTOR DE FUSÃO INTELIGENTE ---

            // 🖌️ Strokes (Traços)
            $newStrokes = is_string($pageData['stroke_data'] ?? []) ? json_decode($pageData['stroke_data'], true) : ($pageData['stroke_data'] ?? []);
            $page->stroke_data = json_encode(Page::mergeJsonItems($page->stroke_data, $newStrokes));

            // 📝 Text Blocks
            $newTexts = is_string($pageData['text_data'] ?? []) ? json_decode($pageData['text_data'], true) : ($pageData['text_data'] ?? []);
            $page->text_data = json_encode(Page::mergeJsonItems($page->text_data, $newTexts));

            // 🖼️ Image Blocks (Com tratamento de Base64)
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

            // Metadados básicos
            $page->is_landscape = $pageData['is_landscape'] ?? $page->is_landscape;
            $page->header_data = $pageData['header_data'] ?? $page->header_data;
            $page->footer_data = $pageData['footer_data'] ?? $page->footer_data;

            $page->save();

            $syncedPages[] = [
                'client_id' => $pageData['client_id'] ?? null,
                'server_id' => $page->id,
                'page_number' => $page->page_number
            ];
        }

        // Avisar os outros dispositivos do utilizador (Não confundir com PageUpdated para colaboração)
        \App\Events\SyncRequested::dispatch($user->id);

        return response()->json(['message' => 'Sincronização concluída.', 'synced_pages' => $syncedPages]);
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