<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
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
        'password',
        'plan_type',
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
    ];


    public function payments()
    {
        return $this->hasMany(Payment::class);
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
