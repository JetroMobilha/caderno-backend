<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Subject;
use App\Models\Notebook;

class NotebookController extends Controller
{
    // Listar todos os cadernos de uma disciplina específica
    public function index(Request $request, $subject_id)
    {
        // FindOrFail garante que a disciplina existe E que pertence a quem fez login
        $subject = $request->user()->subjects()->findOrFail($subject_id);
        
        return response()->json($subject->notebooks);
    }

    // Criar um caderno dentro de uma disciplina
    public function store(Request $request, $subject_id)
    {
        // 1. Validar se a disciplina é realmente do utilizador
        $subject = $request->user()->subjects()->findOrFail($subject_id);

        // 2. Validar os dados enviados pelo Flutter
        $request->validate([
            'title' => 'required|string|max:255',
            'cover_type' => 'nullable|string'
        ]);

        // 3. Criar e associar o caderno
        $notebook = $subject->notebooks()->create([
            'title' => $request->title,
            'cover_type' => $request->cover_type ?? 'basic',
        ]);

        return response()->json($notebook, 201);
    }

    // Apagar um caderno (Mover para a lixeira)
    public function destroy(Request $request, $subject_id, $id)
    {
        // Garante que a disciplina pertence ao utilizador
        $subject = $request->user()->subjects()->findOrFail($subject_id);
        
        // Garante que o caderno pertence a esta disciplina e apaga-o
        $notebook = $subject->notebooks()->findOrFail($id);
        $notebook->delete(); // Como temos o SoftDeletes, isto apenas preenche o deleted_at!

        return response()->json(['message' => 'Caderno movido para a lixeira.']);
    }
}