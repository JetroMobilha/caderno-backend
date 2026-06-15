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

Broadcast::channel('notebook.{notebookId}', function ($user, $notebookId) {
    // Verifica se o utilizador é dono do caderno (via disciplinas)
    // ou se o caderno foi partilhado com ele (via tabela pivô)
    $isOwner = $user->notebooks()->where('notebooks.id', $notebookId)->exists();
    $isCollaborator = $user->sharedNotebooks()->where('notebooks.id', $notebookId)->exists();

    if ($isOwner || $isCollaborator) {
        // Para Presence Channels, retornamos os dados que os outros utilizadores verão
        return ['id' => $user->id, 'name' => $user->name];
    }
});
