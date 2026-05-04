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
        $headerNodeId = (string) $request->header('x-node-id', '');
        $headerTimestamp = (string) $request->header('x-timestamp', '');
        $headerSignature = (string) $request->header('x-signature', '');

        if ($headerNodeId === '' || $headerTimestamp === '' || $headerSignature === '') {
            return ApiResponse::error('Missing required heartbeat headers', 'Bad Request', 400);
        }

        $payload = $request->validated();

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
