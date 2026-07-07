<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Notebook;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class NotebookShareController extends Controller
{
   public function store(Request $request, $notebook_id)
    {
        // 1. Encontrar o caderno ou falhar
        $notebook = Notebook::findOrFail($notebook_id);

        // 2. Segurança: Apenas o verdadeiro DONO da disciplina/caderno pode partilhar
        if ($notebook->subject->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Não tens permissão para partilhar este caderno.'], 403);
        }

        // 3. Validar se o e-mail foi enviado e se existe no sistema
        $request->validate([
            'email' => 'required|email|exists:users,email', // Se o e-mail não existir na BD, avisa logo!
            'role' => 'required|in:viewer,editor'
        ]);

        // 4. Impedir que o dono partilhe o caderno consigo mesmo
        if ($request->email === $request->user()->email) {
            return response()->json(['message' => 'Não podes partilhar o caderno contigo mesmo! 😂'], 400);
        }

        // 5. Encontrar o colega pelo e-mail
        $friend = User::where('email', $request->email)->first();

        // 6. Adicionar à tabela pivô (syncWithoutDetaching evita duplicados se clicar duas vezes)
        $notebook->sharedUsers()->syncWithoutDetaching([
            $friend->id => ['role' => $request->role]
        ]);

        return response()->json([
            'message' => 'Caderno partilhado com sucesso! 🚀',
            'user' => [
                'id' => $friend->id,
                'name' => $friend->name,
                'email' => $friend->email,
                'role' => $request->role
            ]
        ], 200);
    }

    public function destroy(Request $request, Notebook $notebook, User $user)
    {
        Gate::authorize('delete', $notebook); // Só o dono pode remover pessoas
        
        $notebook->sharedUsers()->detach($user->id);

        return response()->json(['message' => 'Acesso removido com sucesso.']);
    }
}