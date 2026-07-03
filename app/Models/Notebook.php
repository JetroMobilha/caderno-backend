<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;  

class Notebook extends Model
{
    use HasFactory,SoftDeletes;

    protected $fillable = [
        'subject_id',
        'title', 
        'cover_type',
        'color',
        'cover_image',
        'line_type',
        'paper_size',
    ];

    // Um caderno pertence a uma disciplina
    public function subject() {
        return $this->belongsTo(Subject::class);
    }

    public function pages() {
        return $this->hasMany(Page::class);
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
