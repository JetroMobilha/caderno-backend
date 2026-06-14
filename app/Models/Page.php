<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
    use HasFactory;

    protected $fillable = [
        'notebook_id', 
        'page_number', 
        'stroke_data',
        'header_data',
        'footer_data',
    ];

    // TRUQUE DO LARAVEL 10: Converte o JSON em Array automaticamente ao ler/gravar
    protected $casts = [
        'stroke_data' => 'array',
        'header_data' => 'array',
        'footer_data' => 'array',
    ];

    // Uma página pertence a um caderno
    public function notebook()
    {
        return $this->belongsTo(Notebook::class);
    }
}