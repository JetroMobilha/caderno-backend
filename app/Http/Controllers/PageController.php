<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Notebook;
use App\Models\Page;

class PageController extends Controller
{

// Listar páginas de um caderno com paginação
    public function index(Request $request, $notebook_id)
    {
        $notebook = \App\Models\Notebook::findOrFail($notebook_id);

        // A MÁGICA: Em vez de usar ->get(), usamos ->paginate(20)
        // Ordenamos pelo número da página para o Flutter receber na ordem certa
        $pages = $notebook->pages()->orderBy('page_number', 'asc')->paginate(20);

        return response()->json($pages);
    }
    
    // Salvar ou atualizar os traços de uma página
    public function store(Request $request, $notebook_id)
    {
        // 1. Validar se o caderno existe (futuramente validamos se pertence ao user)
        $notebook = Notebook::findOrFail($notebook_id);

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
            ['stroke_data' => $request->stroke_data],
            ['header_data' => $request->header_data],
            ['footer_data' => $request->footer_data]
        );

        return response()->json($page, 201);
    }
}