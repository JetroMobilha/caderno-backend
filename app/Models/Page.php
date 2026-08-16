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
        'client_id',
        'updated_at_ms',
        'is_landscape',
        'header_data',
        'footer_data',
        'extracted_text',
        'stroke_data',
        'text_data',
        'ocr_data',
        'image_data',
        'paper_size',
        'is_frozen',
        'background_image_path',
    ];

    protected $casts = [
        'is_landscape' => 'boolean',
        'is_frozen'    => 'boolean',
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

    /**
     * 🆔 Gera um Fingerprint da página para detetar divergências (Deve bater com o Flutter)
     */
    public function generateFingerprint(): string
    {
        $components = [];

        // 1. Strokes
        $strokes = is_array($this->stroke_data) ? $this->stroke_data : json_decode($this->stroke_data ?? '[]', true) ?? [];
        $activeStrokes = collect($strokes)->filter(fn($s) => empty($s['is_deleted']))->sortBy('id');
        foreach ($activeStrokes as $s) {
            $components[] = "s:{$s['id']}:" . ($s['updated_at'] ?? 0);
        }

        // 2. Texts
        $texts = is_array($this->text_data) ? $this->text_data : json_decode($this->text_data ?? '[]', true) ?? [];
        $activeTexts = collect($texts)->filter(fn($t) => empty($t['is_deleted']))->sortBy('id');
        foreach ($activeTexts as $t) {
            $components[] = "t:{$t['id']}:" . ($t['updated_at'] ?? 0);
        }

        // 3. Images
        $images = is_array($this->image_data) ? $this->image_data : json_decode($this->image_data ?? '[]', true) ?? [];
        $activeImages = collect($images)->filter(fn($i) => empty($i['is_deleted']))->sortBy('id');
        foreach ($activeImages as $i) {
            $components[] = "i:{$i['id']}:" . ($i['updated_at'] ?? 0);
        }

        // 4. Metadados Críticos
        $components[] = "f:" . ($this->is_frozen ? 1 : 0);
        $components[] = "ps:" . ($this->paper_size ?? 'A4');

        return implode('|', $components);
    }

    /**
     * Funde os itens JSON (strokes, text, images) garantindo a integridade dos dados.
     *
     * @param mixed $oldData Dados atualmente no banco de dados.
     * @param mixed $newItems Dados vindos do cliente (App Flutter).
     * @param mixed $userId ID do utilizador que enviou os dados.
     * @param string $userRole Papel do utilizador no caderno ('owner', 'editor', 'student').
     * @return array
     */
    public static function mergeJsonItems($oldData, $newItems, $userId = null, $userRole = 'student')
    {
        $oldItems = is_array($oldData) ? $oldData : json_decode($oldData, true) ?? [];
        $newItems = is_array($newItems) ? $newItems : json_decode($newItems, true) ?? [];

        $merged = collect($oldItems)->keyBy('id');

        foreach ($newItems as $newItem) {
            $id = $newItem['id'] ?? null;
            if (!$id) continue;

            if ($merged->has($id)) {
                $oldItem = $merged->get($id);

                // 🛡️ Segurança: Aluno não apaga/move o que é do Professor
                $isOwnerOfItem = ($oldItem['creator_id'] ?? null) == $userId;
                $canEditEverything = in_array($userRole, ['owner', 'editor']);

                if (!$canEditEverything && !$isOwnerOfItem) continue;

                // 🚀 Lógica LWW (Last-Write-Wins) por Milissegundos
                $oldTime = (int)($oldItem['updated_at'] ?? 0);
                $newTime = (int)($newItem['updated_at'] ?? 0);

                if ($newTime >= $oldTime) {
                    // Preserva o criador original
                    $newItem['creator_id'] = $oldItem['creator_id'] ?? $newItem['creator_id'] ?? (string)$userId;
                    $merged->put($id, $newItem);
                }
            } else {
                // Item novo: Atribui quem enviou como criador
                if (empty($newItem['creator_id'])) $newItem['creator_id'] = (string)$userId;
                $merged->put($id, $newItem);
            }
        }
        return $merged->values()->all();
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
