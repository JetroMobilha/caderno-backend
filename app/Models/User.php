<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Contracts\Auth\MustVerifyEmail;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'plan_type',
        'pro_expires_at',
        'email_verified_at',
        'remember_token',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'pro_expires_at' => 'datetime',
    ];


    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    // 2. Método rápido para saber se o estudante é PRO neste exato momento
    public function isPro(): bool
    {
        return $this->pro_expires_at !== null && $this->pro_expires_at->isFuture();
    }

    public function subjects() {
        return $this->hasMany(Subject::class);
    }

    // Atalho para aceder a todos os cadernos do utilizador através das disciplinas
    public function notebooks()
    {
        return $this->hasManyThrough(Notebook::class, Subject::class);
    }

    // Um utilizador tem acesso a VÁRIOS cadernos partilhados
    public function sharedNotebooks()
    {
        return $this->belongsToMany(Notebook::class)->withPivot('role')->withTimestamps();
    }
}
