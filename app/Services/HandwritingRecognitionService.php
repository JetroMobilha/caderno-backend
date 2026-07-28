<?php

namespace App\Services;

use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class HandwritingRecognitionService
{
    public function recognize(string $imagePath, ?string $language = null): array
    {
        if (! is_file($imagePath)) {
            throw new RuntimeException('O ficheiro de imagem não foi encontrado.');
        }

        $binary = config('services.ocr.tesseract_path', 'tesseract');
        $languageCode = $this->normalizeLanguage($language);
        $tessdataDir = config('services.ocr.tessdata_dir');

        $command = [$binary, $imagePath, 'stdout', '--psm', '6'];

        if ($languageCode !== null) {
            $command[] = '-l';
            $command[] = $languageCode;
        }

        $process = new Process($command);

        if ($tessdataDir) {
            $process->setEnv(['TESSDATA_PREFIX' => $tessdataDir]);
        }

        $process->run();

        if (! $process->isSuccessful()) {
            $details = trim($process->getErrorOutput() ?: $process->getOutput());
            throw new RuntimeException(
                $details !== ''
                    ? 'Falha ao reconhecer o texto: ' . $details
                    : 'Falha ao reconhecer o texto. Verifique se o Tesseract está instalado e configurado.'
            );
        }

        return [
            'text' => trim($process->getOutput()),
            'engine' => 'tesseract',
            'language' => $languageCode,
        ];
    }

    public function recognizeFromStrokes(array $strokes, ?string $language = null): array
    {
        $payload = json_encode([
            'strokes' => $strokes,
            'language' => $this->normalizeLanguage($language),
        ], JSON_UNESCAPED_UNICODE);

        if (! is_string($payload) || $payload === '') {
            return [
                'text' => '',
                'engine' => 'tesseract',
                'language' => $this->normalizeLanguage($language),
            ];
        }

        $scriptPath = base_path('scripts/recognize-strokes.mjs');
        $binary = config('services.ocr.node_path', 'node');
        $tessdataDir = config('services.ocr.tessdata_dir');
        $process = new Process([$binary, $scriptPath]);
        $process->setInput($payload);
        $process->setTimeout(120);

        $env = [
            'OCR_TESSERACT_PATH' => config('services.ocr.tesseract_path', 'tesseract'),
            'OCR_TESSDATA_DIR' => $tessdataDir,
        ];

        if ($tessdataDir) {
            $env['TESSDATA_PREFIX'] = $tessdataDir;
        }

        $process->setEnv($env);
        $process->run();

        if (! $process->isSuccessful()) {
            $details = trim($process->getErrorOutput() ?: $process->getOutput());
            throw new RuntimeException(
                $details !== ''
                    ? 'Falha ao reconhecer o texto a partir dos traços: ' . $details
                    : 'Falha ao reconhecer o texto a partir dos traços.'
            );
        }

        $decoded = json_decode($process->getOutput(), true);
        if (is_array($decoded)) {
            return [
                'text' => trim((string) ($decoded['text'] ?? '')),
                'engine' => $decoded['engine'] ?? 'tesseract',
                'language' => $decoded['language'] ?? $this->normalizeLanguage($language),
            ];
        }

        return [
            'text' => trim($process->getOutput()),
            'engine' => 'tesseract',
            'language' => $this->normalizeLanguage($language),
        ];
    }

    private function normalizeLanguage(?string $language): ?string
    {
        if ($language === null || trim($language) === '') {
            return null;
        }

        $normalized = trim($language);

        if (in_array($normalized, ['pt', 'pt_BR', 'por', 'pt-PT', 'pt_BR'], true)) {
            return 'por';
        }

        if (in_array($normalized, ['en', 'eng', 'en_US', 'en-US'], true)) {
            return 'eng';
        }

        return $normalized;
    }
}
