<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'amount',
        'entity',
        'reference',
        'status',
        'plan_type',
        'expires_at',
        'paid_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
        'amount' => 'decimal:2',
    ];
    // Um pagamento pertence a um utilizador
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}