<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    public static function success(
        string $message = 'Success',
        mixed $data = null,
        ?array $meta = null,
        int $statusCode = 200
    ): JsonResponse {
        $payload = [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ];

        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $statusCode);
    }

    public static function error(
        string $message,
        string $error,
        int $statusCode = 400
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error' => $error,
        ], $statusCode);
    }
}
