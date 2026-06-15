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

        // 3. Usar updateOrCreate: Se a página 1 já existir, atualiza os traços. 
        // Se não existir, cria uma nova! Isto é perfeito para sincronização em tempo real.
        $page = $notebook->pages()->updateOrCreate(
            ['page_number' => $request->page_number],
            $request->only(['stroke_data', 'header_data', 'footer_data'])
        );

        // BROADCAST: Avisa os outros utilizadores que a página mudou (Tempo Real)
        // O método toOthers() garante que quem desenhou não receba o próprio traço de volta
        broadcast(new PageUpdated($page))->toOthers();

        return response()->json($page, 201);
    }
}