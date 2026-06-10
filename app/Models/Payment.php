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
        'payment_method',
        'entity',
        'reference',
        'status',
    ];

    // Um pagamento pertence a um utilizador
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}