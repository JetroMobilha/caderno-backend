<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LessonRecording extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'notebook_id',
        'client_id',
        'title',
        'audio_url',
        'duration_seconds',
        'updated_at_ms',
    ];

    public function notebook()
    {
        return $this->belongsTo(Notebook::class);
    }
}
