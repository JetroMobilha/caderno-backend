<?php
namespace App\Http\Middleware;

use Closure;

class DecompressGzipRequest
{
    public function handle($request, Closure $next)
    {
        if ($request->header('Content-Encoding') === 'gzip') {
            $compressed = $request->getContent();
            if (!empty($compressed)) {
                $decompressed = gzdecode($compressed);
                if ($decompressed !== false) {
                    $json = json_decode($decompressed, true);
                    if ($json !== null) {
                        $request->replace($json);
                    }
                }
            }
        }
        return $next($request);
    }
}
