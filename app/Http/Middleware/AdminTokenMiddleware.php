<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminTokenMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $configuredToken = (string) config('monitoring.admin_api_token');

        if ($configuredToken === '') {
            return $next($request);
        }

        $incomingToken = (string) $request->header('x-admin-token', '');

        if ($incomingToken === '' || ! hash_equals($configuredToken, $incomingToken)) {
            return ApiResponse::error('Unauthorized access', 'Unauthorized', 401);
        }

        return $next($request);
    }
}
