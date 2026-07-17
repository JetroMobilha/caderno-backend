<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notebook;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class MarketplaceController extends Controller
{
    /**
     * Retorna o catálogo paginado de cadernos publicados (10 por vez).
     */
    public function index(Request $request)
    {
        $query = Notebook::where('is_published', true)
            ->with('user:id,name');

        // Filtro de pesquisa por título, autor ou descrição
        if ($request->has('q') && !empty($request->q)) {
            $searchTerm = $request->q;
            $query->where(function ($q) use ($searchTerm) {
                $q->where('title', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('author_name', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('description', 'LIKE', "%{$searchTerm}%");
            });
        }

        // Devolve 10 itens por página com estrutura de paginação do Laravel
        $notebooks = $query->orderBy('updated_at', 'desc')->paginate(10);

        return response()->json([
            'data' => $notebooks->items(),
            'current_page' => $notebooks->currentPage(),
            'last_page' => $notebooks->lastPage(),
            'total' => $notebooks->total(),
        ], 200);
    }

    /**
     * Clona um caderno da loja para a conta do utilizador atual.
     */
    public function acquire(Request $request, $id)
    {
        $user = Auth::user();
        
        $originalNotebook = Notebook::where('id', $id)
            ->where('is_published', true)
            ->with('pages')
            ->firstOrFail();

        if ($originalNotebook->user_id === $user->id) {
            return response()->json(['message' => 'Já és o proprietário original deste caderno.'], 400);
        }

        try {
            $clonedNotebook = DB::transaction(function () use ($originalNotebook, $user) {
                
                $defaultSubject = Subject::firstOrCreate(
                    ['user_id' => $user->id, 'name' => 'Matérias Adquiridas 🛒'],
                    ['color' => '#0F4C5C', 'description' => 'Cadernos transferidos da loja']
                );

                $newNotebook = $originalNotebook->replicate();
                $newNotebook->user_id = $user->id;
                $newNotebook->subject_id = $defaultSubject->id;
                $newNotebook->role = 'owner';
                $newNotebook->is_published = false;
                $newNotebook->price = 0.00;
                $newNotebook->original_notebook_id = $originalNotebook->id;
                $newNotebook->save();

                foreach ($originalNotebook->pages as $page) {
                    $newPage = $page->replicate();
                    $newPage->notebook_id = $newNotebook->id;
                    $newPage->save();
                }

                return $newNotebook;
            });

            return response()->json([
                'message' => 'Caderno transferido com sucesso!',
                'notebook' => $clonedNotebook
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erro ao processar a transferência do caderno.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}