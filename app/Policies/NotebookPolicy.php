<?php

namespace App\Policies;

use App\Models\Notebook;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class NotebookPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        //
    }

    /**
     * Determine whether the user can view the model.
     */
   // Regra 1: Quem pode VER (view) o caderno?
    public function view(User $user, Notebook $notebook): bool
    {
        // Se eu for o dono, deixo entrar.
        if ($user->id === $notebook->owner_id) {
            return true;
        }

        // Se não for o dono, verifico se estou na lista de partilha (seja viewer ou editor)
        return $notebook->sharedUsers()->where('user_id', $user->id)->exists();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        //
    }

    /**
     * Determine whether the user can update the model.
     */
    // Regra 2: Quem pode EDITAR (escrever/desenhar/adicionar páginas)?
    public function update(User $user, Notebook $notebook): bool
    {
        if ($user->id === $notebook->owner_id) {
            return true;
        }

        // Para editar, tem de estar na lista e a coluna 'role' tem de ser 'editor'
        $share = $notebook->sharedUsers()->where('user_id', $user->id)->first();
        
        return $share !== null && $share->pivot->role === 'editor';
    }

    /**
     * Determine whether the user can delete the model.
     */
    // Regra 3: Quem pode APAGAR o caderno inteiro?
    public function delete(User $user, Notebook $notebook): bool
    {
        // Só o dono absoluto pode apagar! Editores não podem apagar o caderno.
        return $user->id === $notebook->owner_id;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Notebook $notebook): bool
    {
        //
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Notebook $notebook): bool
    {
        //
    }
}
