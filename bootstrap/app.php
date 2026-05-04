<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\AdminTokenMiddleware;
use App\Http\Middleware\EnsureRequestBodySize;
use App\Support\ApiResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin.token' => AdminTokenMiddleware::class,
            'heartbeat.body' => EnsureRequestBodySize::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ApiException $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ApiResponse::error(
                message: $exception->getMessage(),
                error: $exception->safeError(),
                statusCode: $exception->statusCode(),
            );
        });

        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ApiResponse::error(
                message: 'Invalid request payload',
                error: 'Unprocessable Entity',
                statusCode: 422,
            );
        });

        $exceptions->render(function (\Throwable $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            $statusCode = $exception instanceof HttpExceptionInterface
                ? $exception->getStatusCode()
                : 500;

            $message = $statusCode >= 500
                ? 'Request failed'
                : $exception->getMessage();

            $error = $statusCode >= 500
                ? 'Internal Server Error'
                : ($exception->getMessage() !== '' ? $exception->getMessage() : 'Bad Request');

            return ApiResponse::error(
                message: $message,
                error: $error,
                statusCode: $statusCode,
            );
        });
    })->create();
