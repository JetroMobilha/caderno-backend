<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\Notebook;
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
    $notebook = Notebook::with('subject')->find($notebookId);

    if (!$notebook) {
        return false;
    }

    // 1. O utilizador é o Dono?
    $isOwner = $notebook->subject && $notebook->subject->user_id === $user->id;
    
    // 2. O utilizador é um Colaborador convidado?
    $isCollaborator = $user->sharedNotebooks()->where('notebooks.id', $notebook->id)->exists();

    if ($isOwner || $isCollaborator) {
        // Num Presence Channel, retornar um array autoriza a entrada e partilha estes dados com a sala
        return ['id' => $user->id, 'name' => $user->name, 'avatar' => $user->avatar];
    }

    return false;
});