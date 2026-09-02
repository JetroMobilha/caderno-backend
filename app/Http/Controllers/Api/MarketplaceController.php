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

        // 🚀 Usar o getter owner_id definido no modelo
        if ($originalNotebook->owner_id === $user->id) {
            return response()->json(['message' => 'Já és o proprietário original deste caderno.'], 400);
        }

        try {
            $clonedNotebook = DB::transaction(function () use ($originalNotebook, $user) {
                $nowMs = (int)(microtime(true) * 1000);

                // 🚀 1. Garantir que a pasta de destino existe e está marcada como atualizada para o Sync
                $defaultSubject = Subject::withTrashed()->where('user_id', $user->id)
                    ->where('name', 'Matérias Adquiridas 🛒')
                    ->first();

                if (!$defaultSubject) {
                    $defaultSubject = Subject::create([
                        'user_id' => $user->id,
                        'client_id' => (string) \Illuminate\Support\Str::uuid(),
                        'name' => 'Matérias Adquiridas 🛒',
                        'color' => '#0F4C5C',
                        'updated_at_ms' => $nowMs,
                    ]);
                } else if ($defaultSubject->trashed()) {
                    $defaultSubject->restore();
                    $defaultSubject->update(['updated_at_ms' => $nowMs]);
                }

                // 🚀 2. Replicar o caderno
                $newNotebook = $originalNotebook->replicate();
                $newNotebook->client_id = (string) \Illuminate\Support\Str::uuid();
                $newNotebook->subject_id = $defaultSubject->id;
                $newNotebook->role = 'owner';
                $newNotebook->is_published = false; // Cópias começam privadas
                $newNotebook->price = 0.00;
                $newNotebook->original_notebook_id = $originalNotebook->id;
                $newNotebook->updated_at_ms = $nowMs;
                $newNotebook->save();

                // 🚀 3. Replicar páginas (replicateWithNewIdentities já trata dos timestamps)
                foreach ($originalNotebook->pages as $page) {
                    $page->replicateWithNewIdentities($newNotebook->id);
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
