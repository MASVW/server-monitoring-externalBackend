<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreHeartbeatRequest;
use App\Services\HeartbeatService;
use App\Services\HeartbeatSignatureService;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class HeartbeatController extends Controller
{
    public function __construct(
        private readonly HeartbeatSignatureService $signatureService,
        private readonly HeartbeatService $heartbeatService,
    ) {}

    public function store(StoreHeartbeatRequest $request)
    {
        $payload = $request->validated();

        if ($this->isInternalContractHeartbeat($request, $payload)) {
            return $this->storeInternalContractHeartbeat($request, $payload);
        }

        return $this->storeLegacyContractHeartbeat($request, $payload);
    }

    private function storeLegacyContractHeartbeat(Request $request, array $payload)
    {
        $headerNodeId = (string) $request->header('x-node-id', '');
        $headerTimestamp = (string) $request->header('x-timestamp', '');
        $headerSignature = (string) $request->header('x-signature', '');

        if ($headerNodeId === '' || $headerTimestamp === '' || $headerSignature === '') {
            return ApiResponse::error('Missing required heartbeat headers', 'Bad Request', 400);
        }

        if ($payload['node_id'] !== $headerNodeId) {
            return ApiResponse::error('Header node id does not match payload node id', 'Bad Request', 400);
        }

        $headerTimestampValidation = $this->signatureService->validateTimestampDrift($headerTimestamp);
        if (($headerTimestampValidation['ok'] ?? false) !== true) {
            try {
                $this->heartbeatService->persistInvalidHeartbeat(
                    nodeId: $headerNodeId,
                    payload: $payload,
                    nodeTimestamp: $this->parseTimestamp($payload['timestamp'] ?? null),
                    ipAddress: $request->ip(),
                    userAgent: $request->userAgent(),
                    reason: $headerTimestampValidation['reason'] ?? 'Timestamp validation failed'
                );
            } catch (\Throwable) {
                // Keep primary response stable even if event persistence fails.
            }

            return ApiResponse::error('Invalid request timestamp', 'Unauthorized', 401);
        }

        $payloadTimestampValidation = $this->signatureService->validateTimestampDrift($payload['timestamp']);
        if (($payloadTimestampValidation['ok'] ?? false) !== true) {
            return ApiResponse::error('Invalid payload timestamp', 'Unprocessable Entity', 422);
        }

        $timestampDelta = (int) abs(
            CarbonImmutable::parse($headerTimestamp)->utc()->diffInSeconds(
                CarbonImmutable::parse($payload['timestamp'])->utc(),
                false
            )
        );

        if ($timestampDelta > (int) config('heartbeat.allowed_drift_seconds')) {
            return ApiResponse::error('Timestamp header and payload are inconsistent', 'Bad Request', 400);
        }

        $signatureVerification = $this->signatureService->verifyRequestSignature(
            rawBody: $request->getContent(),
            timestamp: $headerTimestamp,
            signature: $headerSignature,
            secret: (string) config('heartbeat.hmac_secret')
        );

        if (($signatureVerification['ok'] ?? false) !== true) {
            if (($signatureVerification['reason'] ?? '') === 'HMAC secret is not configured') {
                return ApiResponse::error('Heartbeat secret is not configured', 'Internal Server Error', 500);
            }

            try {
                $this->heartbeatService->persistInvalidHeartbeat(
                    nodeId: $headerNodeId,
                    payload: $payload,
                    nodeTimestamp: $this->parseTimestamp($payload['timestamp'] ?? null),
                    ipAddress: $request->ip(),
                    userAgent: $request->userAgent(),
                    reason: 'Invalid signature'
                );
            } catch (\Throwable) {
                // Keep primary response stable even if event persistence fails.
            }

            return ApiResponse::error('Invalid heartbeat signature', 'Unauthorized', 401);
        }

        $processed = $this->heartbeatService->processHeartbeat(
            payload: $payload,
            receivedAt: CarbonImmutable::now('UTC'),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent()
        );

        return ApiResponse::success(
            message: 'Heartbeat received',
            data: [
                'node_id' => $processed['node_id'],
                'status' => $processed['status'],
                'received_at' => $processed['received_at'],
            ]
        );
    }

    private function storeInternalContractHeartbeat(Request $request, array $payload)
    {
        $headerNodeId = (string) $request->header('x-heartbeat-node', '');
        $headerSignature = (string) $request->header('x-heartbeat-signature', '');

        if ($headerNodeId === '' || $headerSignature === '') {
            return ApiResponse::error('Missing required heartbeat headers', 'Bad Request', 400);
        }

        $normalizedPayload = $this->normalizeInternalPayload($payload);

        if ($normalizedPayload['node_id'] !== $headerNodeId) {
            return ApiResponse::error('Header node id does not match payload node id', 'Bad Request', 400);
        }

        $payloadTimestampValidation = $this->signatureService->validateTimestampDrift($normalizedPayload['timestamp']);
        if (($payloadTimestampValidation['ok'] ?? false) !== true) {
            return ApiResponse::error('Invalid payload timestamp', 'Unprocessable Entity', 422);
        }

        $signatureVerification = $this->signatureService->verifyBodySignature(
            rawBody: $request->getContent(),
            signature: $headerSignature,
            secret: (string) config('heartbeat.hmac_secret')
        );

        if (($signatureVerification['ok'] ?? false) !== true) {
            if (($signatureVerification['reason'] ?? '') === 'HMAC secret is not configured') {
                return ApiResponse::error('Heartbeat secret is not configured', 'Internal Server Error', 500);
            }

            try {
                $this->heartbeatService->persistInvalidHeartbeat(
                    nodeId: $headerNodeId,
                    payload: $normalizedPayload,
                    nodeTimestamp: $this->parseTimestamp($normalizedPayload['timestamp']),
                    ipAddress: $request->ip(),
                    userAgent: $request->userAgent(),
                    reason: 'Invalid signature'
                );
            } catch (\Throwable) {
                // Keep primary response stable even if event persistence fails.
            }

            return ApiResponse::error('Invalid heartbeat signature', 'Unauthorized', 401);
        }

        $processed = $this->heartbeatService->processHeartbeat(
            payload: $normalizedPayload,
            receivedAt: CarbonImmutable::now('UTC'),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent()
        );

        return ApiResponse::success(
            message: 'Heartbeat received',
            data: [
                'node_id' => $processed['node_id'],
                'status' => $processed['status'],
                'received_at' => $processed['received_at'],
            ]
        );
    }

    private function isInternalContractHeartbeat(Request $request, array $payload): bool
    {
        return (string) $request->header('x-heartbeat-signature', '') !== ''
            || isset($payload['type'])
            || isset($payload['generatedAt'])
            || isset($payload['node']);
    }

    private function normalizeInternalPayload(array $payload): array
    {
        $hostMetrics = is_array($payload['hostMetrics'] ?? null) ? $payload['hostMetrics'] : [];
        $services = is_array($payload['services'] ?? null) ? $payload['services'] : [];

        return [
            'node_id' => (string) ($payload['node'] ?? 'unknown'),
            'timestamp' => (string) ($payload['generatedAt'] ?? CarbonImmutable::now('UTC')->toIso8601String()),
            'overall_status' => $this->normalizeNodeStatus((string) data_get($payload, 'summary.overallStatus', 'unknown')),
            'host' => [
                'cpu' => is_array($hostMetrics['cpu'] ?? null) ? $hostMetrics['cpu'] : [],
                'memory' => is_array($hostMetrics['memory'] ?? null) ? $hostMetrics['memory'] : [],
                'disk' => is_array($hostMetrics['disk'] ?? null) ? $hostMetrics['disk'] : [],
                'uptime' => $hostMetrics['uptimeSeconds'] ?? null,
                'hostname' => $hostMetrics['hostname'] ?? null,
                'platform' => $hostMetrics['platform'] ?? null,
            ],
            'services' => $this->normalizeInternalServices($services),
            'problems' => $this->normalizeInternalProblems($payload),
        ];
    }

    private function normalizeNodeStatus(string $status): string
    {
        return match (strtolower(trim($status))) {
            'healthy', 'ok' => 'ok',
            'degraded', 'warning' => 'degraded',
            'down' => 'down',
            default => 'unknown',
        };
    }

    private function normalizeServiceStatus(array $service): string
    {
        $value = strtolower(trim((string) ($service['healthStatus'] ?? $service['status'] ?? 'unknown')));

        return match ($value) {
            'healthy', 'online', 'ok' => 'online',
            'warning', 'degraded' => 'degraded',
            'down', 'stopped', 'errored' => 'down',
            default => 'unknown',
        };
    }

    private function normalizeInternalServices(array $services): array
    {
        $normalized = [];

        foreach ($services as $service) {
            if (! is_array($service)) {
                continue;
            }

            $memoryMb = $service['memory_mb'] ?? null;
            if ($memoryMb === null && isset($service['memoryBytes']) && is_numeric($service['memoryBytes'])) {
                $memoryMb = round(((float) $service['memoryBytes']) / (1024 * 1024), 2);
            }

            $normalized[] = [
                'name' => (string) ($service['name'] ?? 'unknown-service'),
                'status' => $this->normalizeServiceStatus($service),
                'pm_id' => isset($service['pm_id']) && is_numeric($service['pm_id']) ? (int) $service['pm_id'] : null,
                'restart_count' => isset($service['restart_count']) && is_numeric($service['restart_count'])
                    ? (int) $service['restart_count']
                    : null,
                'cpu' => isset($service['cpu']) && is_numeric($service['cpu']) ? (float) $service['cpu'] : null,
                'memory_mb' => is_numeric($memoryMb) ? (float) $memoryMb : null,
            ];
        }

        return $normalized;
    }

    private function normalizeInternalProblems(array $payload): array
    {
        $problems = [];
        $connectivity = is_array($payload['connectivity'] ?? null) ? $payload['connectivity'] : [];

        foreach ($connectivity as $target) {
            if (! is_array($target) || ($target['reachable'] ?? true) !== false) {
                continue;
            }

            $problems[] = [
                'type' => 'connectivity',
                'target' => $target['name'] ?? $target['host'] ?? 'unknown',
                'reason' => $target['reason'] ?? 'Target unreachable',
            ];
        }

        $affectedServices = data_get($payload, 'summary.affectedServices', []);
        if (is_array($affectedServices)) {
            foreach ($affectedServices as $serviceName) {
                if (! is_string($serviceName) || trim($serviceName) === '') {
                    continue;
                }

                $problems[] = [
                    'type' => 'service',
                    'target' => $serviceName,
                    'reason' => 'Service reported as affected by internal monitor',
                ];
            }
        }

        return $problems;
    }

    private function parseTimestamp(?string $timestamp): ?CarbonImmutable
    {
        if ($timestamp === null || $timestamp === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($timestamp)->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
