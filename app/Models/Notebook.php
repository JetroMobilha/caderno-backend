<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Notebook extends Model
{
    use HasFactory, SoftDeletes;

    protected $appends = ['participants_preview'];

    protected $fillable = [
        'client_id',
        'subject_id',
        'title',
        'cover_type',
        'color',
        'cover_image',
        'template_type',
        'collaboration_mode',
        'updated_at_ms',
        'tags',
        'is_archived',
        'is_favorite',
        'author_name',
        'is_published',
        'price',
        'description',
        'origin',
        'last_updated_by_name',
        'alternative_title',
        'sharing_type',
        'notifications_enabled',
    ];

    protected $casts = [
        'tags' => 'array',
        'is_archived' => 'boolean',
        'is_favorite' => 'boolean',
        'notifications_enabled' => 'boolean',
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

    /**
     * 🚀 Preview dinâmico para o App (não guardado na base de dados)
     */
    public function getParticipantsPreviewAttribute()
    {
        $owner = $this->subject->user ?? null;
        $participants = [];

        if ($owner) {
            $participants[] = [
                'id' => $owner->id,
                'name' => $owner->name,
                'avatar' => $owner->avatar,
                'role' => 'owner'
            ];
        }

        // Carregar apenas os primeiros 4 convidados (Eager loaded no sync para performance)
        $shared = $this->sharedUsers;
        foreach ($shared as $s) {
            // Evitar duplicar o dono se ele estiver na lista de sharedUsers por algum motivo
            if ($owner && $s->id === $owner->id) continue;

            $participants[] = [
                'id' => $s->id,
                'name' => $s->name,
                'avatar' => $s->avatar,
                'role' => $s->pivot->role ?? 'viewer'
            ];

            if (count($participants) >= 5) break;
        }

        return [
            'total' => ($this->shared_users_count ?? $this->sharedUsers()->count()) + 1,
            'list' => $participants
        ];
    }
}
