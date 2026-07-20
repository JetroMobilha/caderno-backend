<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Notebook;
use App\Models\Page;
use App\Events\SyncRequested;
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
    
    public function store(Request $request, $notebook_id) {
        $notebook = Notebook::findOrFail($notebook_id);

        $request->validate([
            'page_number' => 'required|integer',
            'stroke_data' => 'nullable|array',
            'text_data'   => 'nullable|array',
            'image_data'  => 'nullable|array',
        ]);

        $page = $notebook->pages()->firstOrCreate(['page_number' => $request->page_number]);

        // Aplicar fusão para cada tipo de dado se enviado
        if ($request->has('stroke_data')) {
            $page->stroke_data = json_encode(Page::mergeJsonItems($page->stroke_data, $request->stroke_data));
        }
        if ($request->has('text_data')) {
            $page->text_data = json_encode(Page::mergeJsonItems($page->text_data, $request->text_data));
        }
        if ($request->has('image_data')) {
            $page->image_data = json_encode(Page::mergeJsonItems($page->image_data, $request->image_data));
        }

        $page->save();

        // 📢 Notificar em tempo real os outros colaboradores
        // IMPORTANTE: $request->stroke_data contém apenas os traços novos para o broadcast ser leve
        broadcast(new PageUpdated($notebook->id, $page->id, $page->page_number, $request->stroke_data ?? []))->toOthers();

        return response()->json($page, 201);
    }
}