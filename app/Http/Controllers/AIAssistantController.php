<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Page;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AIAssistantController extends Controller
{
    /**
     * Pesquisa textual rápida na base de dados.
    */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query_text' => 'required|string|min:2|max:200',
            'notebook_id' => 'nullable|integer|exists:notebooks,id'
        ]);

        $userQuery = $validated['query_text'];
        $notebookId = $validated['notebook_id'] ?? null;

        // 1. Expansão com IA (do nosso passo anterior)
        $expandedKeywords = $this->expandQueryForMySQL($userQuery);

        // 2. Junção de todos os termos (os do utilizador + os da IA) para destacar tudo o que for relevante
        $allTermsToHighlight = $userQuery . ' ' . $expandedKeywords;

        $results = Page::query()
            ->select('id', 'notebook_id', 'page_number', 'extracted_text')
            ->whereFullText('extracted_text', $expandedKeywords, ['mode' => 'boolean'])
            ->when($notebookId, fn($q) => $q->where('notebook_id', $notebookId))
            ->limit(20)
            ->get()
            ->map(fn($page) => [
                'id' => $page->id,
                'notebook_id' => $page->notebook_id,
                'page_number' => $page->page_number,
                // AQUI ESTÁ A MÁGICA:
                'snippet' => $this->generateHighlightedSnippet($page->extracted_text, $allTermsToHighlight),
            ]);

        return response()->json([
            'results' => $results
        ]);
    }

    /**
     * Usa IA para transformar a pergunta numa string otimizada para o Full-Text do MySQL.
     */
    private function expandQueryForMySQL(string $query): string
    {
        try {
            $response = Http::withToken(config('services.ai.api_key'))
                ->timeout(3) // Timeout curto: se a IA demorar, não paralisamos a busca
                ->post(config('services.ai.endpoint', 'https://api.openai.com/v1/chat/completions'), [
                    'model' => config('services.ai.model', 'gpt-4o-mini'),
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'És um otimizador de motor de busca MySQL. Devolve APENAS 5 a 8 palavras-chave relevantes, sinónimos e termos técnicos relacionados com a entrada do utilizador. Regra crítica: se o utilizador usar siglas (ex: IA, RH, TI), escreve SEMPRE o significado completo junto com a sigla. Separa tudo apenas por espaços. Sem pontuação.'
                        ],
                        ['role' => 'user', 'content' => $query]
                    ],
                    'temperature' => 0.2,
                ]);

            if ($response->successful()) {
                // Juntamos a query original com os sinónimos limpos
                $synonyms = preg_replace('/[^a-zA-Z0-9\s]/u', '', $response->json('choices.0.message.content'));
                return trim($query . ' ' . $synonyms);
            }
        } catch (\Exception $e) {
            Log::warning('Falha ao expandir query com IA. A usar query original: ' . $e->getMessage());
        }

        // Fallback de segurança: se a IA falhar ou estiver offline, pesquisa só pelo que o utilizador digitou
        return preg_replace('/[^a-zA-Z0-9\s]/u', '', $query);
    }

    /**
     * Gera um resumo do texto da página usando IA (ex: OpenAI / Gemini / Claude).
     */
    public function summarize(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page_id' => 'required|integer|exists:pages,id'
        ]);

        $page = Page::findOrFail($validated['page_id']);
        $text = trim($page->extracted_text ?? '');

        if (empty($text)) {
            return response()->json(['summary' => 'Nada para resumir.'], 400);
        }

        // Se o texto for gigante, limitamos para poupar tokens e custos (ex: 12.000 caracteres ~ 3000 tokens)
        $textToSummarize = Str::limit($text, 12000);

        try {
            $response = Http::withToken(config('services.ai.api_key'))
                ->timeout(45)
                ->post(config('services.ai.endpoint', 'https://api.openai.com/v1/chat/completions'), [
                    'model' => config('services.ai.model', 'gpt-4o-mini'),
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'És um assistente académico. Resume o texto fornecido pelo utilizador de forma clara, destacando os 3 a 5 pontos principais em tópicos. Sê conciso e objetivo.'
                        ],
                        [
                            'role' => 'user',
                            'content' => $textToSummarize
                        ]
                    ],
                    'temperature' => 0.3,
                ]);

            if ($response->failed()) {
                Log::error('Erro na API de Resumo: ' . $response->body());
                return response()->json(['error' => 'Falha ao comunicar com o serviço de IA.'], 502);
            }

            $summary = $response->json('choices.0.message.content');

            return response()->json([
                'success' => true,
                'summary' => trim($summary),
                'page_number' => $page->page_number
            ]);

        } catch (\Exception $e) {
            Log::error('Exceção ao resumir: ' . $e->getMessage());
            return response()->json(['error' => 'Erro interno ao processar o resumo.'], 500);
        }
    }

    /**
    * Gera um trecho de texto (snippet) centrado na palavra encontrada 
    * e destaca os termos com tags <mark> sem quebrar acentos ou segurança HTML.
    */
    private function generateHighlightedSnippet(string $text, string $keywordsString, int $maxLength = 220): string
    {
        // 1. Extrair palavras com 3 ou mais letras (ignora "de", "em", "a", etc.)
        $words = array_filter(
            explode(' ', preg_replace('/[^a-zA-Z0-9\p{L}\s]/u', '', $keywordsString)),
            fn($w) => mb_strlen(trim($w)) >= 3
        );

        if (empty($words)) {
            return e(Str::limit($text, $maxLength));
        }

        // 2. Ordenar da palavra maior para a menor (evita que "carro" substitua dentro de "carroçaria" primeiro)
        usort($words, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        // 3. SMART SNIPPET: Encontrar a posição da 1ª palavra presente no texto
        $firstPos = null;
        foreach ($words as $word) {
            $pos = mb_stripos($text, $word);
            if ($pos !== false) {
                $firstPos = $pos;
                break;
            }
        }

        // 4. Centrar o corte do texto: apanha 60 caracteres ANTES da palavra e o resto DEPOIS
        if ($firstPos !== null && $firstPos > 60) {
            $start = max(0, $firstPos - 60);
            $snippet = '...' . mb_substr($text, $start, $maxLength) . '...';
        } else {
            $snippet = Str::limit($text, $maxLength);
        }

        // 5. SEGURANÇA XSS: Escapar qualquer HTML malicioso que exista no texto extraído do PDF/página ANTES de injetar o nosso HTML
        $safeSnippet = e($snippet);

        // 6. Criar Regex com Limites de Palavra Unicode (\P{L}) para o português
        $escapedWords = array_map('preg_quote', $words);
        // (?<=^|\P{L}) garante que antes da palavra há um início de frase ou algo que não é letra (espaço, ponto)
        // (?=\P{L}|$) garante que depois há algo que não é letra
        $pattern = '/(?<=^|\P{L})(' . implode('|', $escapedWords) . ')(?=\P{L}|$)/iu';

        // 7. Substituir injetando a tag <mark>, mas mantendo o texto original ($1 preserva maiúsculas e acentos)
        return preg_replace($pattern, '<mark class="bg-yellow-200 text-gray-900 font-semibold rounded px-1">$1</mark>', $safeSnippet);
    }
}