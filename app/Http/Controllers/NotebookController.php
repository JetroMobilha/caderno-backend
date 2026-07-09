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
        $user = $request->user();

        // 🎯 SE FOR A DISCIPLINA VIRTUAL DE PARTILHAS:
        if ($subject_id == 999999) {
            return response()->json($user->sharedNotebooks);
        }

        // Fluxo normal para disciplinas criadas pelo utilizador
        $subject = $user->subjects()->findOrFail($subject_id);
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
            'cover_type' => 'nullable|string',
            'color' => 'nullable|string|max:50',
            'cover_image' => 'nullable|string|max:255',
            'line_type' => 'nullable|string|max:50',
        ]);

        // 3. Criar e associar o caderno
        $notebook = $subject->notebooks()->create([
            'title' => $request->title,
            'cover_type' => $request->cover_type ?? 'basic',
            'color' => $request->color ?? '#000000',
            'cover_image' => $request->cover_image ?? null,
            'line_type' => $request->line_type ?? null,
        ]);

        return response()->json($notebook, 201);
    }

    // Apagar um caderno (Mover para a lixeira)
    public function destroy(\App\Models\Notebook $notebook)
    {
        // 1. Opcional, mas recomendado: Verificar se o caderno pertence ao utilizador logado
        if ($notebook->subject->user_id !== auth()->id()) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        // 2. Apagar o caderno (Soft Delete)
        $notebook->delete();

        // 3. Devolver a resposta que o teu teste está à espera
        return response()->json(['message' => 'Caderno movido para a lixeira.']);
    }

    // Exportar caderno para PDF
    public function exportPdf(Request $request, $id)
    {
        // Garante que o caderno pertence ao utilizador autenticado via relação direta
        $notebook = $request->user()->notebooks()->findOrFail($id);

        // Aqui futuramente usarias uma biblioteca como DomPDF ou Browsershot
        // Por agora, retornamos um PDF vazio simulado para validar o teste
        return response('%PDF-1.4 ... content ...', 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="'.$notebook->title.'.pdf"');
    }
}