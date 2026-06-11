<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Notebook extends Model
{
    use HasFactory;

    protected $fillable = ['subject_id', 'title', 'cover_type'];

    // Um caderno pertence a uma disciplina
    public function subject() {
        return $this->belongsTo(Subject::class);
    }
}
