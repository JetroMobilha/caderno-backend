<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Subject;

class SubjectController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        // 1. Ir buscar as disciplinas normais criadas pelo utilizador
        $subjects = Subject::where('user_id', $user->id)
            ->withCount('notebooks')
            ->get()
            ->toArray();

        // 2. Contar quantos cadernos foram partilhados com ele
        $sharedNotebooksCount = $user->sharedNotebooks()->count();

        // 3. Se houver partilhas, injetamos a Disciplina Especial controlada pelo Servidor
        if ($sharedNotebooksCount > 0) {
            $subjects[] = [
                'id' => 999999, // ID reservado para a estante virtual
                'name' => '📚 Partilhados Comigo',
                'color' => '#0F4C5C', 
                'icon' => 'people',
                'notebooks_count' => $sharedNotebooksCount,
                'is_shared_hub' => true 
            ];
        }

        return response()->json($subjects);
    }

    public function destroy($id)
    {
        // Verifica se a disciplina pertence ao utilizador autenticado
        $subject = \App\Models\Subject::where('user_id', auth()->id())->find($id);

        if (!$subject) {
            return response()->json(['message' => 'Disciplina não encontrada.'], 404);
        }

        // Apaga a disciplina (Como o Laravel usa SoftDeletes, ele apenas preenche a coluna deleted_at)
        $subject->delete();

        return response()->json(['message' => 'Disciplina eliminada com sucesso.']);
    }

    // Criar uma nova disciplina (É isto que o nosso teste vai validar!)
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'color' => 'nullable|string|max:9',
            'icon' => 'nullable|string|max:255',
        ]);

        $subject = $request->user()->subjects()->create([
            'name' => $request->name,
            'color' => $request->color ?? '#000000',
            'icon' => $request->icon ?? null,
        ]);

        return response()->json($subject, 201);
    }
}