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

    protected function sendInternalHeartbeat(
        array $payloadOverrides = [],
        ?string $signature = null,
        ?string $headerNodeId = null
    ): TestResponse {
        $generatedAt = CarbonImmutable::now('UTC')->format('Y-m-d\\TH:i:s.v\\Z');

        $payload = array_replace_recursive([
            'type' => 'internal-server-heartbeat',
            'generatedAt' => $generatedAt,
            'node' => 'node-01',
            'summary' => [
                'overallStatus' => 'healthy',
                'serviceCount' => 2,
                'healthyCount' => 2,
                'affectedCount' => 0,
                'affectedServices' => [],
            ],
            'services' => [
                [
                    'name' => 'api-server',
                    'status' => 'healthy',
                    'healthStatus' => 'healthy',
                    'pm_id' => 0,
                    'restart_count' => 1,
                    'cpu' => 0.5,
                    'memory_mb' => 120.3,
                ],
            ],
            'hostMetrics' => [
                'cpu' => ['loadPercent' => 10.2],
                'memory' => ['usedPercent' => 30.4],
                'disk' => [['mount' => '/', 'usedPercent' => 40.1]],
                'uptimeSeconds' => 123456,
                'hostname' => 'node-01',
                'platform' => 'linux',
            ],
            'connectivity' => [],
            'incidents' => [
                'open' => [],
                'recent' => [],
            ],
        ], $payloadOverrides);

        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature ??= hash_hmac('sha256', $rawBody, (string) config('heartbeat.hmac_secret'));
        $headerNodeId ??= (string) ($payload['node'] ?? 'node-01');

        return $this->call(
            method: 'POST',
            uri: '/api/v1/heartbeat',
            parameters: [],
            cookies: [],
            files: [],
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HEARTBEAT_NODE' => $headerNodeId,
                'HTTP_X_HEARTBEAT_SIGNATURE' => $signature,
            ],
            content: $rawBody,
        );
    }
}
