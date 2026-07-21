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
    'extracted_text',  
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

        foreach ($existing as $item) {
            if (isset($item['id'])) $map[$item['id']] = $item;
        }

        foreach ($incomingArray as $item) {
            if (isset($item['id'])) {
                $id = $item['id'];
                if (!empty($item['is_deleted']) && ($item['is_deleted'] == true || $item['is_deleted'] == 1)) {
                    unset($map[$id]);
                } else {
                    $map[$id] = $item;
                }
            }
        }
        return array_values($map);
    }
}