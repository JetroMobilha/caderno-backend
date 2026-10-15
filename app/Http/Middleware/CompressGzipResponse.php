<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class CompressGzipResponse
{
    public function handle($request, Closure $next)
    {
        $response = $next($request);

        // Se o cliente (Flutter) enviou o header a dizer que aceita GZIP e a resposta for de um tipo suportado
        if (($response instanceof JsonResponse || $response instanceof Response) && strpos($request->header('Accept-Encoding'), 'gzip') !== false) {
            $content = $response->getContent();

            // Comprimir apenas se a resposta for maior que 1KB para compensar o uso da CPU
            if (!empty($content) && strlen($content) > 1024) {
                // Comprime nível 6 (padrão ótimo entre velocidade e redução de tamanho)
                $compressed = gzencode($content, 6);

                $response->setContent($compressed);
                $response->headers->set('Content-Encoding', 'gzip');
                $response->headers->set('Content-Length', strlen($compressed));
            }
        }

        return $response;
    }
}
