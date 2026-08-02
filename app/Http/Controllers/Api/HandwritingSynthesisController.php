<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class HandwritingSynthesisController extends Controller
{
    /**
     * Converte texto de máquina em dados de traços de escrita manual.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function synthesize(Request $request)
    {
        $validated = $request->validate([
            'text' => 'required|string|max:1000',
            'color' => 'sometimes|string|regex:/^#[a-fA-F0-9]{6}$/',
            'thickness' => 'sometimes|numeric|min:1|max:20',
            'custom_alphabet' => 'sometimes|nullable|string', // Alfabeto personalizado em base64
        ]);

        $text = $validated['text'];
        $color = $validated['color'] ?? '#2C3E50';
        $thickness = $validated['thickness'] ?? 3;
        $customAlphabet = $validated['custom_alphabet'] ?? null;

        // Caminho para o nosso motor Node.js
        $scriptPath = base_path('scripts/handwriting-engine/engine.mjs');
        $defaultAlphabetPath = base_path('scripts/handwriting-engine/alfabeto.json');
        $nodePath = config('services.ocr.node_path', '/usr/bin/node');

        // Prepara o comando para executar o script Node.js
        $process = new Process([
            $nodePath,
            $scriptPath,
            $defaultAlphabetPath,
            $text,
            $color,
            $thickness,
            $customAlphabet // Passa o alfabeto personalizado (ou null) como último argumento
        ]);

        try {
            $process->mustRun();
            $output = $process->getOutput();
            return response()->json(json_decode($output));
        } catch (ProcessFailedException $exception) {
            Log::error('Falha na síntese de escrita manual: ' . $exception->getMessage());
            return response()->json(['error' => 'Não foi possível gerar a escrita manual.'], 500);
        }
    }
}