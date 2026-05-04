<?php

namespace Tests\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

trait SendsHeartbeatRequests
{
    protected function sendHeartbeat(
        array $payloadOverrides = [],
        ?string $headerTimestamp = null,
        ?string $signature = null,
        ?string $headerNodeId = null
    ): TestResponse {
        $headerTimestamp ??= CarbonImmutable::now('UTC')->format('Y-m-d\\TH:i:s.v\\Z');

        $payload = array_merge([
            'node_id' => 'node-01',
            'timestamp' => $headerTimestamp,
            'overall_status' => 'ok',
            'host' => [
                'cpu' => ['usage_percent' => 10],
                'memory' => ['usage_percent' => 30],
                'disk' => ['usage_percent' => 40],
                'uptime' => 123456,
            ],
            'services' => [
                [
                    'name' => 'api-server',
                    'status' => 'online',
                    'pm_id' => 0,
                    'restart_count' => 1,
                    'cpu' => 0.5,
                    'memory_mb' => 120.3,
                ],
            ],
            'problems' => [],
        ], $payloadOverrides);

        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);

        $signature ??= hash_hmac('sha256', $rawBody.$headerTimestamp, (string) config('heartbeat.hmac_secret'));
        $headerNodeId ??= $payload['node_id'];

        return $this->call(
            method: 'POST',
            uri: '/api/v1/heartbeat',
            parameters: [],
            cookies: [],
            files: [],
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_NODE_ID' => $headerNodeId,
                'HTTP_X_TIMESTAMP' => $headerTimestamp,
                'HTTP_X_SIGNATURE' => $signature,
            ],
            content: $rawBody,
        );
    }
}
