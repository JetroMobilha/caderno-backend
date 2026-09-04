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
        'objects_data',
        'paper_size',
        'is_frozen',
        'is_infinite', // 🚀 v29
        'is_favorite', // 🚀 v29
        'background_image_path',
        'background_config',
        'viewport_matrix', // 🚀 v29
        'layers', // 🚀 v29
    ];

    protected $casts = [
        'is_landscape' => 'boolean',
        'is_frozen'    => 'boolean',
        'is_infinite'  => 'boolean', // 🚀 v29
        'is_favorite'  => 'boolean', // 🚀 v29
        'stroke_data'  => 'array',
        'text_data'    => 'array',
        'ocr_data'     => 'array',
        'image_data'   => 'array',
        'objects_data' => 'array', // 🚀 v29
        'header_data'  => 'array',
        'footer_data'  => 'array',
        'background_config' => 'array',
        'layers'       => 'array', // 🚀 v29
    ];

    protected $appends = ['unified_objects']; // 🚀 Auxiliar para Sync

    public function getUnifiedObjectsAttribute(): array
    {
        if ($this->objects_data && is_array($this->objects_data)) {
            return $this->objects_data;
        }

        // Retro-conversão para o App v29+
        $objs = [];
        if (!empty($this->stroke_data)) foreach($this->stroke_data as $s) { $s['type'] = 'stroke'; $objs[] = $s; }
        if (!empty($this->text_data)) foreach($this->text_data as $t) { $t['type'] = 'text'; $objs[] = $t; }
        if (!empty($this->image_data)) foreach($this->image_data as $i) { $i['type'] = 'image'; $objs[] = $i; }
        return $objs;
    }

    public function notebook()
    {
        return $this->belongsTo(Notebook::class);
    }

    /**
     * 🆔 Gera um Fingerprint da página para detetar divergências (Alinhado com Flutter v29+)
     */
    public function generateFingerprint(): string
    {
        $components = [];

        // 1. Objetos (Ordem por ID)
        $objects = $this->objects_data ?? $this->getUnifiedObjectsAttribute();
        $activeObjects = collect($objects)
            ->filter(fn($o) => !($o['is_deleted'] ?? false))
            ->sortBy(fn($o) => (string)$o['id']);

        foreach ($activeObjects as $o) {
            $components[] = "{$o['type']}:{$o['id']}:" . (int)($o['updated_at'] ?? 0);
        }

        // 2. Metadados de Estado (Fidelidade 100% com Flutter)
        $components[] = "f:" . ($this->is_frozen ? 1 : 0);
        $components[] = "fav:" . ($this->is_favorite ? 1 : 0);
        $components[] = "ps:" . ($this->paper_size ?? 'A4');
        $components[] = "inf:" . ($this->is_infinite ? 1 : 0);

        if ($this->header_data && !empty($this->header_data['section'])) {
            $components[] = "sc:{$this->header_data['section']}:" . ($this->header_data['section_color'] ?? '');
        }

        return implode('|', $components);
    }

    /**
     * ✍️ Extrai os traços (strokes) para processamento de OCR.
     * Tenta ler de objects_data (v29+) primeiro, senão recorre ao stroke_data legado.
     */
    public function getStrokes(): array
    {
        if ($this->objects_data && is_array($this->objects_data)) {
            return collect($this->objects_data)
                ->filter(fn($o) => ($o['type'] ?? '') === 'stroke' && !($o['is_deleted'] ?? false))
                ->values()
                ->toArray();
        }

        return is_array($this->stroke_data) ? $this->stroke_data : json_decode($this->stroke_data ?? '[]', true) ?? [];
    }

    /**
     * 🚀 v29+: Getters para novos tipos de objetos na lista unificada.
     */
    public function getShapes(): array { return $this->filterObjects('shape'); }
    public function getAudios(): array { return $this->filterObjects('audio'); }
    public function getAnimations(): array { return $this->filterObjects('animation'); }
    public function getTables(): array { return $this->filterObjects('table'); }
    public function getLinks(): array { return $this->filterObjects('link'); }
    public function getAttachments(): array { return $this->filterObjects('attachment'); }

    private function filterObjects(string $type): array
    {
        if (!$this->objects_data || !is_array($this->objects_data)) return [];
        return collect($this->objects_data)
            ->filter(fn($o) => ($o['type'] ?? '') === $type && !($o['is_deleted'] ?? false))
            ->values()
            ->toArray();
    }

    private function appendLegacyFingerprint(&$components) {
        $strokes = is_array($this->stroke_data) ? $this->stroke_data : json_decode($this->stroke_data ?? '[]', true) ?? [];
        foreach (collect($strokes)->filter(fn($s) => !($s['is_deleted'] ?? false))->sortBy(fn($s) => (string)$s['id']) as $s) {
            $components[] = "s:{$s['id']}:" . (int)($s['updated_at'] ?? 0);
        }
        $texts = is_array($this->text_data) ? $this->text_data : json_decode($this->text_data ?? '[]', true) ?? [];
        foreach (collect($texts)->filter(fn($t) => !($t['is_deleted'] ?? false))->sortBy(fn($t) => (string)$t['id']) as $t) {
            $components[] = "t:{$t['id']}:" . (int)($t['updated_at'] ?? 0);
        }
        $images = is_array($this->image_data) ? $this->image_data : json_decode($this->image_data ?? '[]', true) ?? [];
        foreach (collect($images)->filter(fn($i) => !($i['is_deleted'] ?? false))->sortBy(fn($i) => (string)$i['id']) as $i) {
            $components[] = "i:{$i['id']}:" . (int)($i['updated_at'] ?? 0);
        }
    }

    /**
     * Funde os itens JSON garantindo a integridade dos dados (Last Write Wins).
     */
    public static function mergeJsonItems($oldData, $newData, $userId = null, $userRole = 'student')
    {
        $oldItems = is_array($oldData) ? $oldData : json_decode($oldData ?? '[]', true) ?? [];
        $newItems = is_array($newData) ? $newData : json_decode($newData ?? '[]', true) ?? [];

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
     * Alias para compatibilidade com a nova estrutura de objetos.
     */
    public static function mergeObjects($oldData, $newData, $userId = null, $userRole = 'student')
    {
        return self::mergeJsonItems($oldData, $newData, $userId, $userRole);
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

    /**
     * 🚀 v29+: Simplifica traços dentro da lista unificada de objetos.
     */
    public static function simplifyUnifiedObjects(array $objects, $epsilon = 0.4): array
    {
        foreach ($objects as &$o) {
            if (($o['type'] ?? '') === 'stroke' && isset($o['points']) && is_array($o['points']) && count($o['points']) > 15) {
                $o['points'] = self::ramerDouglasPeucker($o['points'], $epsilon);
            }
        }
        return $objects;
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
        $x = $p['dx'] ?? $p['x'] ?? 0; $y = $p['dy'] ?? $p['y'] ?? 0;
        $x1 = $start['dx'] ?? $start['x'] ?? 0; $y1 = $start['dy'] ?? $start['y'] ?? 0;
        $x2 = $end['dx'] ?? $end['x'] ?? 0; $y2 = $end['dy'] ?? $end['y'] ?? 0;

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
     * 🚀 CLONAGEM PROFUNDA v29+: Replicar a página com novas identidades para todos os objetos (unificados ou legados).
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

        // 1. Clonar Objetos Unificados (v29+)
        if ($this->objects_data && is_array($this->objects_data)) {
            $objects = $this->objects_data;
            foreach ($objects as &$o) {
                $o['id'] = (string) Str::uuid();
                $o['updated_at'] = $nowMs;
                $o['page_number'] = $pNum;
                $o['synced_with_cloud'] = 1;
            }
            $clone->objects_data = $objects;
        }

        // 2. Clonar Metadados de Layout (v29+)
        $clone->viewport_matrix = $this->viewport_matrix;
        $clone->layers = $this->layers; // JSON array deep copy by replicate()

        // 2. Clonar Legados (Strokes, Textos, Imagens) para segurança
        $this->cloneLegacyData($clone, $nowMs, $pNum);

        $clone->save();
        return $clone;
    }

    private function cloneLegacyData(&$clone, $nowMs, $pNum) {
        $strokes = $this->stroke_data ?? [];
        foreach ($strokes as &$s) { $s['id'] = (string) Str::uuid(); $s['updated_at'] = $nowMs; $s['page_number'] = $pNum; }
        $clone->stroke_data = $strokes;

        $texts = $this->text_data ?? [];
        foreach ($texts as &$t) { $t['id'] = (string) Str::uuid(); $t['updated_at'] = $nowMs; $t['page_number'] = $pNum; }
        $clone->text_data = $texts;

        $images = $this->image_data ?? [];
        foreach ($images as &$i) { $i['id'] = (string) Str::uuid(); $i['updated_at'] = $nowMs; $i['page_number'] = $pNum; }
        $clone->image_data = $images;
    }
}
