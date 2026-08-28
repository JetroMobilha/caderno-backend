<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Page extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'notebook_id',
        'page_number',
        'client_id',
        'updated_at_ms',
        'is_landscape',
        'header_data',
        'footer_data',
        'extracted_text',
        'line_type',
        'line_spacing',
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

        // 1. Strokes - Ordenação por ID (String UUID) e uso do timestamp
        $strokes = is_array($this->stroke_data) ? $this->stroke_data : json_decode($this->stroke_data ?? '[]', true) ?? [];
        $activeStrokes = collect($strokes)
            ->filter(fn($s) => !($s['is_deleted'] ?? false))
            ->sortBy(fn($s) => (string)$s['id']);

        foreach ($activeStrokes as $s) {
            $components[] = "s:{$s['id']}:" . (int)($s['updated_at'] ?? 0);
        }

        // 2. Texts
        $texts = is_array($this->text_data) ? $this->text_data : json_decode($this->text_data ?? '[]', true) ?? [];
        $activeTexts = collect($texts)
            ->filter(fn($t) => !($t['is_deleted'] ?? false))
            ->sortBy(fn($t) => (string)$t['id']);

        foreach ($activeTexts as $t) {
            $components[] = "t:{$t['id']}:" . (int)($t['updated_at'] ?? 0);
        }

        // 3. Images
        $images = is_array($this->image_data) ? $this->image_data : json_decode($this->image_data ?? '[]', true) ?? [];
        $activeImages = collect($images)
            ->filter(fn($i) => !($i['is_deleted'] ?? false))
            ->sortBy(fn($i) => (string)$i['id']);

        foreach ($activeImages as $i) {
            $components[] = "i:{$i['id']}:" . (int)($i['updated_at'] ?? 0);
        }

        // 4. Metadados Críticos
        $components[] = "f:" . ($this->is_frozen ? 1 : 0);
        $components[] = "ps:" . ($this->paper_size ?? 'A4');
        $components[] = "lt:" . ($this->line_type ?? 'ruled');
        $components[] = "ls:" . ($this->line_spacing ?? '28');

        return implode('|', $components);
    }

    /**
     * Funde os itens JSON (strokes, text, images) garantindo a integridade dos dados.
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
                $isOwnerOfItem = ($oldItem['creator_id'] ?? null) == $userId;
                $canEditEverything = in_array($userRole, ['owner', 'editor']);

                if (!$canEditEverything && !$isOwnerOfItem) continue;

                $oldTime = (int)($oldItem['updated_at'] ?? 0);
                $newTime = (int)($newItem['updated_at'] ?? 0);

                if ($newTime >= $oldTime) {
                    $newItem['creator_id'] = $oldItem['creator_id'] ?? $newItem['creator_id'] ?? (string)$userId;
                    $merged->put($id, $newItem);
                }
            } else {
                if (empty($newItem['creator_id'])) $newItem['creator_id'] = (string)$userId;
                $merged->put($id, $newItem);
            }
        }

        return $merged->values()->all();
    }

    /**
     * Simplifica os traços da página para reduzir o consumo de banco de dados e largura de banda.
     * Implementa o algoritmo de Ramer-Douglas-Peucker.
     */
    public static function simplifyStrokes($strokes, $epsilon = 0.4)
    {
        if (!is_array($strokes)) return $strokes;

        foreach ($strokes as &$stroke) {
            if (isset($stroke['points']) && is_array($stroke['points']) && count($stroke['points']) > 15) {
                $stroke['points'] = self::ramerDouglasPeucker($stroke['points'], $epsilon);
            }
        }
        return $strokes;
    }

    private static function ramerDouglasPeucker($points, $epsilon)
    {
        if (count($points) <= 2) return $points;

        $maxDistance = 0;
        $index = 0;
        $end = count($points) - 1;

        for ($i = 1; $i < $end; $i++) {
            $distance = self::perpendicularDistance($points[$i], $points[0], $points[$end]);
            if ($distance > $maxDistance) {
                $index = $i;
                $maxDistance = $distance;
            }
        }

        if ($maxDistance > $epsilon) {
            $recursiveResult1 = self::ramerDouglasPeucker(array_slice($points, 0, $index + 1), $epsilon);
            $recursiveResult2 = self::ramerDouglasPeucker(array_slice($points, $index), $epsilon);

            return array_merge(array_slice($recursiveResult1, 0, -1), $recursiveResult2);
        } else {
            return [$points[0], $points[$end]];
        }
    }

    private static function perpendicularDistance($p, $start, $end)
    {
        $x = $p['dx']; $y = $p['dy'];
        $x1 = $start['dx']; $y1 = $start['dy'];
        $x2 = $end['dx']; $y2 = $end['dy'];

        $numerator = abs(($y2 - $y1) * $x - ($x2 - $x1) * $y + $x2 * $y1 - $y2 * $x1);
        $denominator = sqrt(pow($y2 - $y1, 2) + pow($x2 - $x1, 2));

        return ($denominator == 0) ? sqrt(pow($x - $x1, 2) + pow($y - $y1, 2)) : ($numerator / $denominator);
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
            $context['notebook'] = ['id' => $notebook->id, 'title' => $notebook->title];
            $subject = $notebook->subject;
            if ($subject) {
                $context['subject'] = ['id' => $subject->id, 'name' => $subject->name];
            }
        }

        $context['page'] = ['id' => $this->id, 'number' => $this->page_number];
        return array_filter($context, static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * 🚀 CLONAGEM PROFUNDA: Replicar a página com novas identidades para todos os elementos internos.
     */
    public function replicateWithNewIdentities(int $newNotebookId, ?int $newPageNumber = null)
    {
        $clone = $this->replicate();
        $clone->notebook_id = $newNotebookId;
        if ($newPageNumber !== null) {
            $clone->page_number = $newPageNumber;
        }
        $clone->client_id = (string) Str::uuid();
        $clone->updated_at_ms = (int)(microtime(true) * 1000);

        $nowMs = $clone->updated_at_ms;
        $pNum = $clone->page_number;

        // 1. Clonar Strokes
        $strokes = $this->stroke_data ?? [];
        foreach ($strokes as &$s) {
            $s['id'] = (string) Str::uuid();
            $s['updated_at'] = $nowMs;
            $s['page_number'] = $pNum;
            $s['synced_with_cloud'] = 1; // Já nasce no servidor
        }
        $clone->stroke_data = $strokes;

        // 2. Clonar Textos
        $texts = $this->text_data ?? [];
        foreach ($texts as &$t) {
            $t['id'] = (string) Str::uuid();
            $t['updated_at'] = $nowMs;
            $t['page_number'] = $pNum;
            $t['synced_with_cloud'] = 1;
        }
        $clone->text_data = $texts;

        // 3. Clonar Imagens
        $images = $this->image_data ?? [];
        foreach ($images as &$i) {
            $i['id'] = (string) Str::uuid();
            $i['updated_at'] = $nowMs;
            $i['page_number'] = $pNum;
            $i['synced_with_cloud'] = 1;
        }
        $clone->image_data = $images;

        $clone->save();
        return $clone;
    }
}
