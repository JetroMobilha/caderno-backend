<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;  

class Subject extends Model
{
    use HasFactory,SoftDeletes;

    protected $fillable = ['user_id', 'name', 'color'];

    // Uma disciplina pertence a um utilizador
    public function user() {
        return $this->belongsTo(User::class);
    }

    // Uma disciplina tem vários cadernos
    public function notebooks() {
        return $this->hasMany(Notebook::class);
    }
}
