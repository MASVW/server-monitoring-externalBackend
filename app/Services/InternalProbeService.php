<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class InternalProbeService
{
    public function probe(string $url): array
    {
        $startedAt = microtime(true);
        $timeout = max(1, (int) config('monitoring.internal_probe_timeout_seconds', 5));

        try {
            $response = Http::timeout($timeout)->get($url);
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
            $statusCode = $response->status();

            return [
                'ok' => $response->successful(),
                'status_code' => $statusCode,
                'latency_ms' => $latencyMs,
                'error' => $response->successful() ? null : "HTTP {$statusCode}",
            ];
        } catch (\Throwable $exception) {
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

            return [
                'ok' => false,
                'status_code' => null,
                'latency_ms' => $latencyMs,
                'error' => trim($exception->getMessage()),
            ];
        }
    }
}

