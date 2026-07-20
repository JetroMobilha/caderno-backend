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
        'is_landscape',  
        'header_data',
        'footer_data',
        'stroke_data',
        'text_data',     
        'image_data',    
    ];
 
    protected $casts = [
        'is_landscape' => 'boolean', 
        'stroke_data'  => 'array',
        'text_data'    => 'array',    
        'image_data'   => 'array',    
         
    ];

    public function notebook()
    {
        return $this->belongsTo(Notebook::class);
    }

    public static function mergeJsonItems($existingJson, $incomingArray) {
        $existing = is_string($existingJson) ? json_decode($existingJson, true) : ($existingJson ?? []);
        $map = [];

        // 1. Indexar o que já existe pelo ID (UUID vindo do Flutter)
        foreach ($existing as $item) {
            if (isset($item['id'])) $map[$item['id']] = $item;
        }

        // 2. Fundir com o que está a chegar
        foreach ($incomingArray as $item) {
            if (isset($item['id'])) {
                $id = $item['id'];
                // Se o Flutter marcou como apagado, removemos da nuvem
                if (!empty($item['is_deleted']) && $item['is_deleted'] == true) {
                    unset($map[$id]);
                } else {
                    $map[$id] = $item;
                }
            }
        }
        return array_values($map);
    }
}