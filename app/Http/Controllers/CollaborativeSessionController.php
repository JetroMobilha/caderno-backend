<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\CollaborativeSession;
use App\Models\CollaborativeSessionParticipant;
use App\Models\Notebook;
use Illuminate\Support\Facades\Log;

class CollaborativeSessionController extends Controller
{
    /**
     * Webhook do Reverb.
     */
    public function webhook(Request $request)
    {
        $events = $request->input('events', array());

        foreach ($events as $event) {
            $evtName = isset($event['name']) ? $event['name'] : '';
            $channel = isset($event['channel']) ? $event['channel'] : '';
            $userId = isset($event['user_id']) ? $event['user_id'] : null;

            if (!$userId || strpos($channel, 'presence-notebook.') !== 0) {
                continue;
            }

            $notebookId = str_replace('presence-notebook.', '', $channel);

            $sessSearch = array('notebook_id' => $notebookId, 'is_active' => true);
            $sessCreate = array('started_at' => now());
            $session = CollaborativeSession::firstOrCreate($sessSearch, $sessCreate);

            if ($evtName === 'member_added') {
                $pSearch = array('session_id' => $session->id, 'user_id' => $userId, 'left_at' => null);
                $pData = array(
                    'joined_at' => now(),
                    'socket_id' => (isset($event['socket_id']) ? $event['socket_id'] : null)
                );
                CollaborativeSessionParticipant::updateOrCreate($pSearch, $pData);
            } elseif ($evtName === 'member_removed') {
                $participant = CollaborativeSessionParticipant::where('session_id', $session->id)
                    ->where('user_id', $userId)
                    ->whereNull('left_at')
                    ->first();

                if ($participant) {
                    $participant->update(array('left_at' => now()));
                }

                $activeCount = $session->activeParticipants()->count();
                if ($activeCount === 0) {
                    $session->update(array('is_active' => false, 'ended_at' => now()));
                }
            }
        }

        return response()->json(array('status' => 'ok'));
    }

    /**
     * Retorna o status da sessão e quem é a autoridade atual.
     */
    public function getStatus(Request $request, $notebook_id)
    {
        $session = CollaborativeSession::where('notebook_id', $notebook_id)
            ->where('is_active', true)
            ->first();

        if (!$session) {
            return response()->json(array('active' => false));
        }

        $authority = $session->activeParticipants()
            ->orderBy('joined_at', 'asc')
            ->with('user')
            ->first();

        $authorityId = $authority ? $authority->user_id : null;
        $authorityName = ($authority && $authority->user) ? $authority->user->name : null;
        $participantsCount = $session->activeParticipants()->count();

        return response()->json(array(
            'active' => true,
            'authority_id' => $authorityId,
            'authority_name' => $authorityName,
            'participants_count' => $participantsCount,
            'started_at' => $session->started_at
        ));
    }
}
