<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\Subject;
use App\Models\Notebook;
use App\Models\Page;  

use Illuminate\Support\Facades\Log;

class SyncController extends Controller
{
    // =========================================================================
    // ☁️ FASE 1: RECEBER DISCIPLINAS (COM PROTOCOLO DE FUSÃO ANTI-DUPLICAÇÃO)
    // =========================================================================
    public function push(Request $request)
    {
        $user = $request->user();
        $clientSubjects = $request->input('subjects', []);
        $syncedSubjects = [];

        foreach ($clientSubjects as $subjectData) {
            $subject = null;

            // 1. Se o telemóvel já enviou um server_id, é uma atualização normal de um registo existente
            if (!empty($subjectData['server_id'])) {
                $subject = Subject::where('user_id', $user->id)
                    ->where('id', $subjectData['server_id'])
                    ->first();
            }

            // 2. 🚀 O RADAR ANTI-COLISÃO: Se é uma criação nova (sem server_id),
            // procuramos se o aluno JÁ TEM uma disciplina com este mesmo nome (ignorando maiúsculas e espaços)
            if (!$subject) {
                $cleanName = trim(strtolower($subjectData['name']));
                
                $subject = Subject::where('user_id', $user->id)
                    ->whereRaw('LOWER(TRIM(name)) = ?', [$cleanName])
                    ->orderBy('id', 'asc') // Pega a primeira que foi criada (A Principal!)
                    ->first();
            }

            // 3. DECISÃO TÁTICA DE FUSÃO:
            if ($subject) {
                // Se já existia, MANTEMOS OS DADOS DO ANTIGO COMO PRINCIPAL!
                // Só atualizamos o nome/cor se o comando veio de um ID que já existia no servidor.
                if (!empty($subjectData['server_id'])) {
                    $subject->update([
                        'name'  => trim($subjectData['name']),
                        'color' => $subjectData['color'],
                        'icon'  => $subjectData['icon'],
                    ]);
                } else {
                    Log::info("🧲 [Fusão] Disciplina duplicada evitda! O telemóvel tentou criar '{$subjectData['name']}', mas fundimos com o ID {$subject->id}.");
                }
            } else {
                // 4. Se o nome está livre, criamos uma disciplina 100% nova!
                $subject = Subject::create([
                    'user_id' => $user->id,
                    'name'    => trim($subjectData['name']),
                    'color'   => $subjectData['color'],
                    'icon'    => $subjectData['icon'],
                ]);
            }

            // 5. Devolvemos o ID oficial. O telemóvel vai assumir este ID e fundir os cadernos!
            $syncedSubjects[] = [
                'client_id' => $subjectData['id'], // ID local do SQLite
                'server_id' => $subject->id,       // ID oficial (Novo ou Fundido!)
            ];
        }

        return response()->json([
            'message' => 'Disciplinas sincronizadas e purificadas contra duplicações.',
            'synced_subjects' => $syncedSubjects
        ]);
    }

    public function pull(Request $request)
    {
        $user = $request->user();
        $lastSyncedAt = $request->query('last_synced_at');
        $query = Subject::where('user_id', $user->id);

        if ($lastSyncedAt) {
            $query->where('updated_at', '>', $lastSyncedAt);
        }

        return response()->json([
            'message' => 'Rastreio concluído.',
            'subjects' => $query->get(),
            'server_time' => now()->toIso8601String() 
        ]);
    }

    // =========================================================================
    // ☁️ FASE 2: RECEBER CADERNOS (COM PROTOCOLO DE FUSÃO DE CAPAS)
    // =========================================================================
    public function pushNotebooks(Request $request)
    {
        $user = $request->user();
        $clientNotebooks = $request->input('notebooks', []);
        $syncedNotebooks = [];

        foreach ($clientNotebooks as $notebookData) {
            // 1. Descobrimos qual é a Disciplina Mãe no servidor
            $subject = Subject::where('user_id', $user->id)
                ->where('id', $notebookData['subject_id'])
                ->first();

            $subjectId = $subject ? $subject->id : $notebookData['subject_id'];
            $notebook = null;

            // 2. Se já tem server_id, busca diretamente para atualizar
            if (!empty($notebookData['server_id'])) {
                $notebook = Notebook::where('id', $notebookData['server_id'])
                    ->where('subject_id', $subjectId)
                    ->first();
            }

            // 3. 🚀 O RADAR DE CAPAS: Se é novo, procura por cadernos com o MESMO TÍTULO dentro desta disciplina
            if (!$notebook) {
                $cleanTitle = trim(strtolower($notebookData['title']));

                $notebook = Notebook::where('subject_id', $subjectId)
                    ->whereRaw('LOWER(TRIM(title)) = ?', [$cleanTitle])
                    ->orderBy('id', 'asc') // O mais antigo vence e mantém a capa original!
                    ->first();
            }

            // 4. EXECUÇÃO DA FUSÃO OU CRIAÇÃO:
            if ($notebook) {
                // Se já existia um caderno com esse nome na disciplina, mantemos a cor e formato do antigo!
                if (!empty($notebookData['server_id'])) {
                    $notebook->update([
                        'title'       => trim($notebookData['title']),
                        'cover_type'  => $notebookData['cover_type'] ?? 'color',
                        'color'       => $notebookData['color'],
                        'cover_image' => $notebookData['cover_image'],
                        'line_type'   => $notebookData['line_type'],
                        'paper_size'  => $notebookData['paper_size'] ?? 'A4',
                    ]);
                } else {
                    Log::info("🧲 [Fusão] Caderno duplicado evitado! Fundido '{$notebookData['title']}' no ID {$notebook->id}.");
                }
            } else {
                // Se não existia, encadernamos um novo!
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

            $syncedNotebooks[] = [
                'client_id' => $notebookData['id'],
                'server_id' => $notebook->id,
            ];
        }

        return response()->json([
            'message' => 'Cadernos sincronizados e fundidos com sucesso.',
            'synced_notebooks' => $syncedNotebooks
        ]);
    }

    // =========================================================================
    // ☁️ FASE 3: RECEBER FOLHAS (COM PROTOCOLO ANTI-COLISÃO DE PAGINAÇÃO)
    // =========================================================================
    public function pushPages(Request $request)
    {
        $user = $request->user();
        $clientPages = $request->input('pages', []);
        $syncedPages = [];

        foreach ($clientPages as $pageData) {
            $notebookId = $pageData['notebook_id'];
            $targetPageNumber = $pageData['page_number'];
            $page = null;

            // 1. Se já tem server_id (id), é uma atualização de um desenho existente na nuvem
            if (!empty($pageData['id'])) {
                $page = Page::where('id', $pageData['id'])
                    ->where('notebook_id', $notebookId)
                    ->first();
            }

            // 2. 🚀 O RADAR DE PAGINAÇÃO: Se é uma folha NOVA (sem server_id)...
            if (!$page) {
                // Verificamos se este caderno já tem alguma folha a ocupar este número exato!
                $collision = Page::where('notebook_id', $notebookId)
                    ->where('page_number', $targetPageNumber)
                    ->exists();

                if ($collision) {
                    // 🛡️ PROTOCOLO REFORÇO NA RETAGUARDA: Encontramos a última folha do caderno
                    $maxPage = Page::where('notebook_id', $notebookId)->max('page_number');
                    
                    // Se a última era a 3, esta nova passa a ser a 4!
                    $newPageNumber = ($maxPage ? $maxPage : 0) + 1;
                    
                    Log::info("📑 [Paginação] Colisão evitada no Caderno {$notebookId}! A Folha local {$targetPageNumber} foi reposicionada como Folha {$newPageNumber} na nuvem.");
                    
                    $targetPageNumber = $newPageNumber;
                }
            }

            // 3. Processamento de Imagens Base64 (Mantém a mesma lógica limpa que fizemos ontem)
            $imagesArray = $pageData['image_data'] ?? [];
            $cleanImages = [];
            foreach ($imagesArray as $img) {
                if (!empty($img['image_base64'])) {
                    try {
                        $decodedImage = base64_decode($img['image_base64']);
                        $filename = 'img_' . uniqid() . '_' . Str::slug($img['id']) . '.png';
                        $storagePath = 'notebook_images/' . $filename;
                        Storage::disk('public')->put($storagePath, $decodedImage);
                        $img['image_path'] = asset('storage/' . $storagePath);
                    } catch (\Exception $e) {
                        Log::error("Erro na imagem Base64: " . $e->getMessage());
                    }
                }
                unset($img['image_base64']);
                $cleanImages[] = $img;
            }

            // 4. Gravação ou Atualização usando o número da página (possivelmente corrigido!)
            if ($page) {
                // Atualização de folha existente
                $page->update([
                    'is_landscape' => $pageData['is_landscape'] ?? false,
                    'header_data'  => $pageData['header_data'],
                    'footer_data'  => $pageData['footer_data'],
                    'stroke_data'  => $pageData['stroke_data'] ?? [],
                    'text_data'    => $pageData['text_data'] ?? [],
                    'image_data'   => $cleanImages,
                ]);
            } else {
                // Criação da folha nova na posição livre ou anexada no fim
                $page = Page::create([
                    'notebook_id'  => $notebookId,
                    'page_number'  => $targetPageNumber, // <--- O SEGREDO ESTÁ AQUI!
                    'is_landscape' => $pageData['is_landscape'] ?? false,
                    'header_data'  => $pageData['header_data'],
                    'footer_data'  => $pageData['footer_data'],
                    'stroke_data'  => $pageData['stroke_data'] ?? [],
                    'text_data'    => $pageData['text_data'] ?? [],
                    'image_data'   => $cleanImages,
                ]);
            }

            // 5. Devolvemos ao telemóvel o ID oficial E O NÚMERO DA PÁGINA CORRIGIDO!
            $syncedPages[] = [
                'client_id'   => $pageData['client_id'], // O ID local do SQLite
                'server_id'   => $page->id,              // O ID da nuvem
                'page_number' => $page->page_number      // O número real em que ela ficou encadernada!
            ];
        }

        return response()->json([
            'message' => 'Folhas sincronizadas e paginação re-ordenada sem colisões.',
            'synced_pages' => $syncedPages
        ]);
    }
}