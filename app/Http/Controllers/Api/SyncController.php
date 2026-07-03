<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\Subject;
use App\Models\Notebook;
use App\Models\Page; // Garante que criaste o Modelo Page no Laravel!

class SyncController extends Controller
{
    // =========================================================================
    // ☁️ FASE 1: RECEBER DISCIPLINAS (PULL/PUSH) - Já implementado
    // =========================================================================
    public function push(Request $request)
    {
        $user = $request->user();
        $clientSubjects = $request->input('subjects', []);
        $syncedSubjects = [];

        foreach ($clientSubjects as $subjectData) {
            $subject = Subject::updateOrCreate(
                [
                    'id' => $subjectData['server_id'], 
                    'user_id' => $user->id
                ],
                [
                    'name' => $subjectData['name'],
                    'color' => $subjectData['color'],
                    'icon' => $subjectData['icon'],
                ]
            );

            $syncedSubjects[] = [
                'client_id' => $subjectData['id'], 
                'server_id' => $subject->id,       
            ];
        }

        return response()->json([
            'message' => 'Disciplinas sincronizadas.',
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
    // ☁️ FASE 2: RECEBER CADERNOS (PUSH)
    // =========================================================================
    public function pushNotebooks(Request $request)
    {
        $user = $request->user();
        $clientNotebooks = $request->input('notebooks', []);
        $syncedNotebooks = [];

        foreach ($clientNotebooks as $notebookData) {
            // RESOLUÇÃO DE RELACIONAMENTO: O telemóvel envia o subject_id local do SQLite.
            // Precisamos de descobrir qual é o ID oficial desse assunto no servidor!
            $subject = Subject::where('user_id', $user->id)
                ->where('id', $notebookData['subject_id']) // Se já enviares o server_id mapeado no Flutter
                ->first();

            // Fallback tático caso ainda use IDs locais no payload provisório
            $subjectId = $subject ? $subject->id : $notebookData['subject_id'];

            $notebook = Notebook::updateOrCreate(
                [
                    'id' => $notebookData['server_id'],
                ],
                [
                    'subject_id' => $subjectId,
                    'title' => $notebookData['title'],
                    'cover_type' => $notebookData['cover_type'] ?? 'color',
                    'color' => $notebookData['color'],
                    'cover_image' => $notebookData['cover_image'],
                    'line_type' => $notebookData['line_type'],
                    'paper_size' => $notebookData['paper_size'] ?? 'A4',
                ]
            );

            $syncedNotebooks[] = [
                'client_id' => $notebookData['id'], // ID local do SQLite
                'server_id' => $notebook->id,       // ID real da Nuvem
            ];
        }

        return response()->json([
            'message' => 'Cadernos sincronizados.',
            'synced_notebooks' => $syncedNotebooks
        ]);
    }

    // =========================================================================
    // ☁️ FASE 3: RECEBER FOLHAS E EXTRAIR IMAGENS BASE64 (PUSH)
    // =========================================================================
    public function pushPages(Request $request)
    {
        $user = $request->user();
        $clientPages = $request->input('pages', []);
        $syncedPages = [];

        foreach ($clientPages as $pageData) {
            
            $imagesArray = $pageData['image_data'] ?? [];
            $cleanImages = [];

            // 🚀 OPERAÇÃO DESEMPACOTAMENTO: Varre as fotos à procura de Base64
            foreach ($imagesArray as $img) {
                if (!empty($img['image_base64'])) {
                    try {
                        // 1. Decodifica o texto Base64 de volta para o ficheiro binário original
                        $decodedImage = base64_decode($img['image_base64']);
                        
                        // 2. Gera um nome único militar anti-colisão para a foto
                        $filename = 'img_' . uniqid() . '_' . Str::slug($img['id']) . '.png';
                        $storagePath = 'notebook_images/' . $filename;

                        // 3. Salva fisicamente na pasta storage/app/public/notebook_images/
                        Storage::disk('public')->put($storagePath, $decodedImage);

                        // 4. Substitui o caminho local do telemóvel pelo URL oficial da Nuvem!
                        // Ex: http://35.205.132.251:8080/storage/notebook_images/img_123.png
                        $img['image_path'] = asset('storage/' . $storagePath);
                        
                    } catch (\Exception $e) {
                        \Log::error("Erro ao processar imagem Base64: " . $e->getMessage());
                    }
                }

                // 🔥 PURIFICAÇÃO ABSOLUTA: Destrói a chave do Base64 para ela não entrar
                // na base de dados. O MySQL vai receber apenas o URL leve!
                unset($img['image_base64']);
                $cleanImages[] = $img;
            }

            // 5. Salva ou atualiza a folha no cofre relacional
            // Nota: Garante que os campos stroke_data, text_data e image_data estão configurados como $casts=['json'] no modelo Page!
            $page = Page::updateOrCreate(
                [
                    'id' => $pageData['id'] ?? null, // server_id se houver
                    'notebook_id' => $pageData['notebook_id'],
                    'page_number' => $pageData['page_number']
                ],
                [
                    'is_landscape' => $pageData['is_landscape'] ?? false,
                    'header_data' => $pageData['header_data'],
                    'footer_data' => $pageData['footer_data'],
                    'stroke_data' => $pageData['stroke_data'] ?? [],
                    'text_data' => $pageData['text_data'] ?? [],
                    'image_data' => $cleanImages, // Grava o array limpo contendo as URLs públicas
                ]
            );

            $syncedPages[] = [
                'client_id' => $pageData['client_id'], // ID local do SQLite
                'server_id' => $page->id,              // ID oficial gerado pelo Laravel
            ];
        }

        return response()->json([
            'message' => 'Folhas e multimédia sincronizados com sucesso.',
            'synced_pages' => $syncedPages
        ]);
    }
}