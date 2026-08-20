<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CollaborativeSessionParticipant extends Model
{
    protected $fillable = ['session_id', 'user_id', 'role', 'can_speak', 'joined_at', 'left_at', 'last_heartbeat', 'socket_id'];

    protected $casts = [
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
        'last_heartbeat' => 'datetime',
    ];

    public function session()
    {
        return $this->belongsTo(CollaborativeSession::class, 'session_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
