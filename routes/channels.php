<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\Notebook;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// 🟢 CANAL DE PRESENÇA UNIVERSAL (Usado pelo teu RealtimeService no Flutter)
Broadcast::channel('notebook.{notebookId}', function ($user, $notebookId) {
    // 1. Encontrar o caderno com a disciplina mãe carregada
    $notebook = Notebook::with('subject')->find($notebookId);

    if (!$notebook) {
        return false;
    }

    // 2. Segurança: O utilizador é o Dono da Disciplina?
    $isOwner = $notebook->subject && $notebook->subject->user_id === $user->id;
    
    // 3. Segurança: O utilizador é um Colaborador convidado na tabela pivô?
    $isCollaborator = $user->sharedNotebooks()->where('notebooks.id', $notebook->id)->exists();

    // 🎯 Se passar num dos testes, autoriza com dados completos para a sala virtual!
    if ($isOwner || $isCollaborator) {
        return [
            'id' => $user->id, 
            'name' => $user->name,
            'email' => $user->email,
            'role' => $isOwner ? 'owner' : 'editor'
        ];
    }

    return false;
}, ['middleware' => ['auth:sanctum']]);