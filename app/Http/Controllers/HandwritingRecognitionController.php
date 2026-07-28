<?php

namespace App\Http\Controllers;

use App\Models\Notebook;
use App\Models\Page;
use App\Services\HandwritingRecognitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class HandwritingRecognitionController extends Controller
{
    public function __construct(protected HandwritingRecognitionService $recognitionService)
    {
    }

    /**
     * Reconhece texto manuscrito a partir da imagem carregada e, se for fornecido o contexto do caderno,
     * guarda automaticamente o resultado na página correspondente da base de dados.
     */
    public function recognize(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'language' => ['nullable', 'string', 'max:10'],
            'notebook_id' => ['nullable', 'integer', 'exists:notebooks,id'],
            'page_number' => ['nullable', 'integer', 'min:1'],
            'save_to_database' => ['nullable', 'boolean'],
        ]);

        $file = $request->file('image');

        try {
            $result = $this->recognitionService->recognize($file->getRealPath(), $validated['language'] ?? null);
        } catch (RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 502);
        }

        $page = null;
        $savedToDatabase = false;

        if (! empty($validated['notebook_id'])) {
            $user = $request->user();

            if ($user) {
                $notebook = $user->notebooks()->find($validated['notebook_id'])
                    ?? $user->sharedNotebooks()->find($validated['notebook_id']);

                if (! $notebook) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Sem permissão para aceder a este caderno.',
                    ], 403);
                }
            } else {
                $notebook = Notebook::findOrFail($validated['notebook_id']);
            }

            $pageNumber = $validated['page_number'] ?? 1;

            $page = $notebook->pages()->firstOrCreate([
                'page_number' => $pageNumber,
            ]);

            $recognizedText = trim($result['text'] ?? '');
            $page->extracted_text = $recognizedText;

            $textEntry = $page->buildOcrTextEntry($recognizedText, $result);
            $page->ocr_data = Page::mergeJsonItems($page->ocr_data, [$textEntry]);
            $page->save();
            $savedToDatabase = true;
        }

        return response()->json([
            'success' => true,
            'text' => $result['text'] ?? '',
            'engine' => $result['engine'] ?? 'tesseract',
            'language' => $result['language'] ?? null,
            'saved_to_database' => $savedToDatabase,
            'page_id' => $page?->id,
            'notebook_id' => $validated['notebook_id'] ?? null,
            'context' => $page?->buildOcrContext(),
        ]);
    }
}
