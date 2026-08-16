<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CollaborativeSessionPage extends Model
{
    protected $fillable = ['session_id', 'page_id'];

    public function session()
    {
        return $this->belongsTo(CollaborativeSession::class);
    }

    public function page()
    {
        return $this->belongsTo(Page::class);
    }
}
