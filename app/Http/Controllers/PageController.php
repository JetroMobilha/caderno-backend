<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Notebook;
use App\Models\Page;
use App\Events\PageUpdated; // Certifica-te de criar este evento

class PageController extends Controller
{

// Listar páginas de um caderno com paginação
    public function index(Request $request, $notebook_id)
    {
        // Permite que donos e colaboradores listem as páginas
        $notebook = $request->user()->notebooks()->find($notebook_id)
                 ?? $request->user()->sharedNotebooks()->findOrFail($notebook_id);

        // A MÁGICA: Em vez de usar ->get(), usamos ->paginate(20)
        // Ordenamos pelo número da página para o Flutter receber na ordem certa
        $pages = $notebook->pages()->orderBy('page_number', 'asc')->paginate(20);

        return response()->json($pages);
    }
    
    // Salvar ou atualizar os traços de uma página
    public function store(Request $request, $notebook_id)
    {
        // Validação de permissão: Apenas o dono ou um colaborador com role 'editor' pode salvar
        $notebook = $request->user()->notebooks()->find($notebook_id);
        
        if (!$notebook) {
            $shared = $request->user()->sharedNotebooks()->where('role', 'editor')->findOrFail($notebook_id);
            $notebook = $shared;
        }

        // 2. Validar os dados do Flutter
        $request->validate([
            'page_number' => 'required|integer',
            'stroke_data' => 'required|array' ,
            'header_data' => 'nullable|array',
            'footer_data' => 'nullable|array',
            // Garante que vem um array/json válido
        ]);

        // Encontra a página ou cria uma nova se não existir
        $page = $notebook->pages()->firstOrNew(['page_number' => $request->page_number]);

        // Obtém os traços existentes, ou um array vazio se for uma nova página
        $existingStrokes = $page->stroke_data ?? [];

        // Obtém os novos traços da requisição (assumimos que o Flutter envia apenas os novos)
        $newStrokes = $request->stroke_data;

        // Combina os traços existentes com os novos
        $mergedStrokes = array_merge($existingStrokes, $newStrokes);

        // Atualiza a página com os traços combinados e outros dados
        $page->stroke_data = $mergedStrokes;
        // Atualiza header_data e footer_data apenas se forem fornecidos na requisição
        if ($request->has('header_data')) {
            $page->header_data = $request->header_data;
        }
        if ($request->has('footer_data')) {
            $page->footer_data = $request->footer_data;
        }
        $page->save(); // Salva a página

        // BROADCAST: Avisa os outros utilizadores que a página mudou (Tempo Real)
        // O método toOthers() garante que quem desenhou não receba o próprio traço de volta
        broadcast(new PageUpdated($notebook->id, $page->id, $page->page_number, $newStrokes))->toOthers();

        return response()->json($page, 201);
    }
}