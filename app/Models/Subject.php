<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Subject extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'client_id',
        'name',
        'color',
        'icon',
        'is_archived',
        'is_favorite',
        'updated_at_ms',
    ];

    protected $casts = [
        'is_archived' => 'boolean',
        'is_favorite' => 'boolean',
    ];

    protected static function booted()
    {
        static::deleting(function ($subject) {
            // 🚀 Quando apagamos uma disciplina, garantimos que o timestamp de alta precisão
            // é atualizado para que os clientes detetem a mudança via Sync.
            $subject->updated_at_ms = (int)(microtime(true) * 1000);
            $subject->save();

            // 🗑️ ELIMINAÇÃO EM CASCATA: Apagar todos os cadernos associados.
            // Usamos ->each(fn($n) => $n->delete()) para disparar o hook 'deleting' do modelo Notebook.
            $subject->notebooks()->each(function ($notebook) {
                $notebook->delete();
            });
        });
    }

    // Uma disciplina pertence a um utilizador
    public function user() {
        return $this->belongsTo(User::class);
    }

    // Uma disciplina tem vários cadernos
    public function notebooks() {
        return $this->hasMany(Notebook::class);
    }
}
