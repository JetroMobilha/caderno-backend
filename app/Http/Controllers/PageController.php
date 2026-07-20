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
        $page = $notebook->pages()->firstOrCreate(['page_number' => $request->page_number]);

        // Fusão inteligente para traços finais via API
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

        // 📢 Broadcast com o sender_id para o Flutter ignorar o eco
        broadcast(new PageUpdated(
            $notebook->id,
            $page->id,
            $page->page_number,
            $request->stroke_data ?? [],
            $request->user()->id // sender_id
        ))->toOthers();

        return response()->json($page, 201);
    }
}