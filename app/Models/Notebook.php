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
        'configuration',
    ];

    protected $casts = [
        'tags' => 'array',
        'is_archived' => 'boolean',
        'is_favorite' => 'boolean',
        'notifications_enabled' => 'boolean',
        'configuration' => 'array',
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

        // 🟢 NOVIDADE: Contagem Online em tempo real
        $activeSession = CollaborativeSession::where('notebook_id', $this->id)
            ->where('is_active', true)
            ->first();
        $onlineCount = $activeSession ? $activeSession->activeParticipants()->count() : 0;

        return [
            'total' => ($this->shared_users_count ?? $this->sharedUsers()->count()) + 1,
            'list' => $participants,
            'online_count' => $onlineCount
        ];
    }

    /**
     * 🚀 CLONAGEM PROFUNDA: Replicar o caderno e todas as suas folhas.
     */
    public function replicateWithNewIdentities(int $targetSubjectId)
    {
        $clone = $this->replicate();
        $clone->subject_id = $targetSubjectId;
        $clone->client_id = (string) Str::uuid();
        $clone->updated_at_ms = (int)(microtime(true) * 1000);
        // Cópias manuais começam como privadas
        $clone->is_published = 0;
        $clone->save();

        foreach ($this->pages as $page) {
            $page->replicateWithNewIdentities($clone->id);
        }

        return $clone;
    }
}
