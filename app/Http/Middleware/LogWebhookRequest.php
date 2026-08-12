<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LogWebhookRequest
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->is('api/webhooks/reverb')) {
            Log::info("🚀 [Middleware] Webhook detetado no Pipeline!", [
                'ip' => $request->ip(),
                'method' => $request->method(),
                'headers' => $request->headers->all()
            ]);
        }
        return $next($request);
    }
}
