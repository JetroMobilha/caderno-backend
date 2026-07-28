<?php

namespace App\Jobs;

use App\Models\Page;
use App\Services\HandwritingRecognitionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessPageOcr implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $timeout;

    public function __construct(
        public int $pageId,
        public ?string $language = null,
    ) {
        $this->onQueue(config('services.ocr.queue_name', 'ocr'));
        $this->tries = (int) config('services.ocr.queue_tries', 3);
        $this->timeout = (int) config('services.ocr.queue_timeout', 600);
    }

    public function handle(HandwritingRecognitionService $recognitionService): void
    {
        $page = Page::with('notebook.subject')->find($this->pageId);

        if (! $page) {
            return;
        }

        if (! empty($page->extracted_text)) {
            return;
        }

        $strokes = [];
        $decodedStrokeData = $page->stroke_data;

        if (is_string($decodedStrokeData)) {
            $decodedStrokeData = json_decode($decodedStrokeData, true);
        }

        if (is_array($decodedStrokeData)) {
            $strokes = array_values(array_filter($decodedStrokeData, static fn ($item) => is_array($item) || is_object($item)));
        }

        if (empty($strokes)) {
            return;
        }

        try {
            $result = $recognitionService->recognizeFromStrokes($strokes, $this->language);
            $recognizedText = trim((string) ($result['text'] ?? ''));

            if ($recognizedText === '') {
                return;
            }

            $page->extracted_text = $recognizedText;
            $page->ocr_data = Page::mergeJsonItems($page->ocr_data, [$page->buildOcrTextEntry($recognizedText, $result)]);
            $page->save();
        } catch (\Throwable $exception) {
            Log::warning('Falha ao processar OCR da página ' . $this->pageId . ': ' . $exception->getMessage());
            throw $exception;
        }
    }
}
