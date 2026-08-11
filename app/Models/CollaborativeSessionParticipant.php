<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CollaborativeSessionParticipant extends Model
{
    protected $fillable = ['session_id', 'user_id', 'joined_at', 'left_at', 'last_heartbeat', 'socket_id'];

    public function session()
    {
        return $this->belongsTo(CollaborativeSession::class, 'session_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
