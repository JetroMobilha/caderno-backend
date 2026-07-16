<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Subject;
use App\Events\SyncRequested;

class SubjectController extends Controller
{
    // =========================================================================
    // 📚 LER DISCIPLINAS (Padrão Web / API Normal)
    // =========================================================================
    public function index(Request $request)
    {
        $user = $request->user();

        // 🟢 PADRÃO DA INDÚSTRIA: Retorna apenas disciplinas reais (ativas)
        $subjects = Subject::where('user_id', $user->id)
            ->withCount('notebooks')
            ->get();

        return response()->json($subjects);
    }

    // =========================================================================
    // ➕ CRIAR DISCIPLINA
    // =========================================================================
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
            'icon' => $request->icon ?? 'book',
        ]);

        
        SyncRequested::dispatch($request->user()->id);

        return response()->json($subject, 201);
    }

    // =========================================================================
    // ✏️ ATUALIZAR DISCIPLINA (Faltava este método! 🚀)
    // =========================================================================
    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'color' => 'nullable|string|max:9',
            'icon' => 'nullable|string|max:255',
        ]);

        // 🛡️ Segurança: Busca a matéria garantindo que o dono é o utilizador autenticado
        $subject = Subject::where('user_id', $request->user()->id)->find($id);

        if (!$subject) {
            return response()->json(['message' => 'Disciplina não encontrada ou sem permissão de acesso.'], 404);
        }

        // Atualiza os dados que vieram no Request
        $subject->update([
            'name' => $request->name ?? $subject->name,
            'color' => $request->color ?? $subject->color,
            'icon' => $request->icon ?? $subject->icon,
        ]);

        SyncRequested::dispatch($request->user()->id);

        return response()->json($subject, 200);
    }

    // =========================================================================
    // 🗑️ APAGAR DISCIPLINA
    // =========================================================================
    public function destroy($id)
    {
        $subject = Subject::where('user_id', auth()->id())->find($id);

        if (!$subject) {
            return response()->json(['message' => 'Disciplina não encontrada.'], 404);
        }

        // 🗑️ Executa o Soft Delete
        $subject->delete();

        SyncRequested::dispatch(auth()->id());

        return response()->json(['message' => 'Disciplina eliminada com sucesso.']);
    }
}