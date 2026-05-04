<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRequestBodySize
{
    public function handle(Request $request, Closure $next): Response
    {
        $maxBytes = (int) config('heartbeat.max_body_size_bytes', 102400);

        $contentLength = (int) $request->header('content-length', 0);
        if ($contentLength > 0 && $contentLength > $maxBytes) {
            return ApiResponse::error('Heartbeat payload too large', 'Payload Too Large', 413);
        }

        $bodyLength = strlen($request->getContent());
        if ($bodyLength > $maxBytes) {
            return ApiResponse::error('Heartbeat payload too large', 'Payload Too Large', 413);
        }

        return $next($request);
    }
}
