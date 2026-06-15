<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('presence-notebook.{notebookId}', function ($user, $notebookId) {
    // Adicionamos o 'with' para garantir que a disciplina é carregada
    $notebook = \App\Models\Notebook::with('subject')->find($notebookId);

    if (!$notebook) {
        return false;
    }

    // Verificação muito mais simples e à prova de falhas:
    $isOwner = $notebook->subject && $notebook->subject->user_id === $user->id;
    
    // A verificação do colaborador continua igual (pois já estava a funcionar)
    $isCollaborator = $user->sharedNotebooks()->where('notebooks.id', $notebook->id)->exists();

    if ($isOwner || $isCollaborator) {
        return ['id' => $user->id, 'name' => $user->name];
    }

    return false;
});