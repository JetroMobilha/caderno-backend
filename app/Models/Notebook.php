<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Notebook extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'client_id',
        'subject_id',
        'title',
        'cover_type',
        'color',
        'cover_image',
        'line_type',
        'paper_size',
        'line_spacing',
        'template_type',
        'collaboration_mode',
        'updated_at_ms',
    ];

    protected static function booted()
    {
        static::deleting(function ($notebook) {
            // 🚀 Atualizar timestamp de alta precisão no Soft Delete
            $notebook->updated_at_ms = (int)(microtime(true) * 1000);
            $notebook->save();
        });
    }

    // Um caderno pertence a uma disciplina
    public function subject() {
        return $this->belongsTo(Subject::class);
    }

    public function pages() {
        return $this->hasMany(Page::class);
    }

    public function lessonRecordings() {
        return $this->hasMany(LessonRecording::class);
    }

    // Um caderno pode ser partilhado com VÁRIOS utilizadores
    public function sharedUsers()
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    // Método auxiliar para descobrirmos rapidamente quem é o dono real
    public function getOwnerIdAttribute()
    {
        return $this->subject->user_id;
    }
}
