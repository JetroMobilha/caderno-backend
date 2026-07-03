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
}