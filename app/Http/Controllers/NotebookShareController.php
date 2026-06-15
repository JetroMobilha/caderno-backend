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
        // 1. Validar se o caderno existe
        $notebook = Notebook::findOrFail($notebook_id);

        // 2. Segurança: Garantir que quem está a partilhar é o verdadeiro DONO do caderno
        if ($notebook->subject->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Não tens permissão para partilhar este caderno.'], 403);
        }

        // 3. Validar os dados enviados
        $request->validate([
            'email' => 'required|email|exists:users,email', // O email tem de existir no sistema!
            'role' => 'required|in:viewer,editor'
        ]);

        // 4. Encontrar o colega pelo email
        $friend = User::where('email', $request->email)->first();

        // 5. Adicionar o colega à tabela pivô com o syncWithoutDetaching (evita duplicados)
        $notebook->sharedUsers()->syncWithoutDetaching([
            $friend->id => ['role' => $request->role]
        ]);

        return response()->json(['message' => 'Caderno partilhado com sucesso!'], 200);
    }

    public function destroy(Request $request, Notebook $notebook, User $user)
    {
        Gate::authorize('delete', $notebook); // Só o dono pode remover pessoas
        
        $notebook->sharedUsers()->detach($user->id);

        return response()->json(['message' => 'Acesso removido com sucesso.']);
    }
}