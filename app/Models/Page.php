<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; 
use Illuminate\Support\Str;

class Page extends Model
{
    use HasFactory,SoftDeletes;
    protected $fillable = [
        'notebook_id', 
        'page_number', 
        'is_landscape',  
        'header_data',
        'footer_data',
        'extracted_text',  
        'stroke_data',
        'text_data',     
        'ocr_data',
        'image_data',    
    ];
     
    protected $casts = [
        'is_landscape' => 'boolean', 
        'stroke_data'  => 'array',
        'text_data'    => 'array',    
        'ocr_data'     => 'array',
        'image_data'   => 'array',    
        'header_data'  => 'array',
        'footer_data'  => 'array',
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

    public function buildOcrTextEntry(string $recognizedText, array $result = []): array
    {
        $context = $this->buildOcrContext();

        $entry = [
            'id' => (string) Str::uuid(),
            'type' => 'ocr',
            'text' => trim($recognizedText),
            'engine' => $result['engine'] ?? 'tesseract',
            'language' => $result['language'] ?? null,
            'created_at' => now()->toISOString(),
            'context' => $context,
            'subject_id' => $context['subject']['id'] ?? null,
            'notebook_id' => $context['notebook']['id'] ?? null,
            'page_id' => $context['page']['id'] ?? null,
            'page_number' => $context['page']['number'] ?? null,
        ];

        return array_filter($entry, static fn ($value) => $value !== null && $value !== '');
    }

    public function buildOcrContext(): array
    {
        $context = [];
        $notebook = $this->notebook;

        if ($notebook) {
            $context['notebook'] = [
                'id' => $notebook->id,
                'title' => $notebook->title,
            ];

            $subject = $notebook->subject;
            if ($subject) {
                $context['subject'] = [
                    'id' => $subject->id,
                    'name' => $subject->name,
                ];
            }
        }

        $context['page'] = [
            'id' => $this->id,
            'number' => $this->page_number,
        ];

        return array_filter($context, static fn ($value) => $value !== null && $value !== '');
    }
}