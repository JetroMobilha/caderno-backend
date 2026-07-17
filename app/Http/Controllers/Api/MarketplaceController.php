<?php
namespace App\Http\
Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notebook;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class MarketplaceController extends Controller
{
    /**
     * Retorna o catálogo de cadernos publicados.
     */
    public function index(Request $request)
    {
        $query = Notebook::where('is_published', true)
            ->with('user:id,name'); // Traz os dados básicos do criador se necessário

        // Filtro de pesquisa por título, autor ou descrição
        if ($request->has('q') && !empty($request->q)) {
            $searchTerm = $request->q;
            $query->where(function ($q) use ($searchTerm) {
                $q->where('title', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('author_name', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('description', 'LIKE', "%{$searchTerm}%");
            });
        }

        // Ordena pelos mais recentes
        $notebooks = $query->orderBy('updated_at', 'desc')->paginate(20);

        return response()->json($notebooks->items(), 200);
    }

    /**
     * Clona um caderno da loja para a conta do utilizador atual.
     */
    public function acquire(Request $request, $id)
    {
        $user = Auth::user();
        
        // 1. Encontra o caderno original na loja
        $originalNotebook = Notebook::where('id', $id)
            ->where('is_published', true)
            ->with('pages') // Carrega todas as páginas do caderno
            ->firstOrFail();

        // 2. Evita que o autor compre o próprio caderno
        if ($originalNotebook->user_id === $user->id) {
            return response()->json(['message' => 'Já és o proprietário original deste caderno.'], 400);
        }

        // 3. Executa a clonagem profunda dentro de uma transação segura
        try {
            $clonedNotebook = DB::transaction(function () use ($originalNotebook, $user) {
                
                // A) Garante uma disciplina padrão para receber o caderno importado
                $defaultSubject = Subject::firstOrCreate(
                    ['user_id' => $user->id, 'name' => 'Matérias Adquiridas 🛒'],
                    ['color' => '#0F4C5C', 'description' => 'Cadernos transferidos da loja']
                );

                // B) Duplica o cabeçalho do caderno
                $newNotebook = $originalNotebook->replicate();
                $newNotebook->user_id = $user->id;
                $newNotebook->subject_id = $defaultSubject->id;
                $newNotebook->role = 'owner';
                $newNotebook->is_published = false; // O clone entra privado na secretária do aluno
                $newNotebook->price = 0.00;
                $newNotebook->original_notebook_id = $originalNotebook->id;
                $newNotebook->save();

                // C) Duplica todas as páginas e vetores (Canvas) para o novo caderno
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