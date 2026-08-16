<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CollaborativeSession extends Model
{
    protected $fillable = ['notebook_id', 'alternative_title', 'is_active', 'started_at', 'ended_at'];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function notebook()
    {
        return $this->belongsTo(Notebook::class);
    }

    public function participants()
    {
        return $this->hasMany(CollaborativeSessionParticipant::class, 'session_id');
    }

    public function sharedPages()
    {
        return $this->hasMany(CollaborativeSessionPage::class, 'session_id');
    }

    public function activeParticipants()
    {
        return $this->participants()->whereNull('left_at');
    }
}
