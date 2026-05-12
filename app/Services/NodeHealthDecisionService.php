<?php

namespace App\Services;

use App\Models\MonitoredNode;
use Carbon\CarbonImmutable;

class NodeHealthDecisionService
{
    public function __construct(
        private readonly NodeReasonService $nodeReasonService,
    ) {}

    /**
     * @param array{ok:bool,status_code:int|null,latency_ms:int|null,error:string|null}|null $probeResult
     */
    public function evaluate(
        MonitoredNode $node,
        CarbonImmutable $now,
        ?array $probeResult = null,
        bool $probeAttempted = false,
        bool $strictProbeReachability = true
    ): array {
        $timeoutSeconds = max(1, (int) ($node->timeout_threshold_seconds ?: 180));
        $heartbeatStatus = $this->nodeReasonService->normalizeNodeStatus((string) ($node->heartbeat_status ?: $node->current_status));

        $heartbeatFresh = false;
        if ($node->last_heartbeat_at !== null) {
            $heartbeatFresh = $now->diffInSeconds($node->last_heartbeat_at, true) <= $timeoutSeconds;
        }

        $probeUrl = $this->nodeReasonService->resolveProbeUrl((string) $node->node_id);
        $probeConfigured = $probeUrl !== null;
        $probeThreshold = $this->nodeReasonService->probeFailureThreshold();

        $probeState = $this->nodeReasonService->normalizeProbeState($node->probe_state);
        $probeFailCount = max(0, (int) ($node->probe_fail_count ?? 0));
        $lastProbeError = $node->last_probe_error;
        $lastProbeCheckedAt = $node->last_probe_checked_at;
        $lastProbeOkAt = $node->last_probe_ok_at;

        if (! $heartbeatFresh) {
            $nextStatus = 'down';
            $reasonCode = 'heartbeat_timeout';
        } else {
            if (! $probeConfigured) {
                $probeState = 'unconfigured';
                $probeFailCount = 0;
                $lastProbeCheckedAt = $now;
                $lastProbeError = 'Probe URL not configured';
            } elseif ($probeAttempted) {
                $lastProbeCheckedAt = $now;

                if (($probeResult['ok'] ?? false) === true) {
                    $probeState = 'reachable';
                    $probeFailCount = 0;
                    $lastProbeOkAt = $now;
                    $lastProbeError = null;
                } else {
                    $probeState = 'unreachable';
                    $probeFailCount += 1;
                    $lastProbeError = $this->clipProbeError((string) ($probeResult['error'] ?? 'Target unreachable'));
                }
            }

            if ($heartbeatStatus === 'down') {
                $nextStatus = 'down';
                $reasonCode = 'heartbeat_reported_down';
            } elseif ($probeState === 'unconfigured') {
                $nextStatus = 'degraded';
                $reasonCode = 'probe_not_configured';
            } elseif ($probeState === 'unreachable' && $probeFailCount >= $probeThreshold) {
                $nextStatus = 'degraded';
                $reasonCode = 'isp_down';
            } elseif ($strictProbeReachability && $probeConfigured && $probeState !== 'reachable') {
                $nextStatus = 'degraded';
                $reasonCode = 'isp_down';
            } elseif (in_array($heartbeatStatus, ['degraded', 'unknown'], true)) {
                $nextStatus = 'degraded';
                $reasonCode = 'heartbeat_reported_degraded';
            } else {
                $nextStatus = 'ok';
                $reasonCode = 'healthy';
            }
        }

        $serverName = $this->nodeReasonService->resolveServerName($node);

        return [
            'status' => $nextStatus,
            'reason_code' => $reasonCode,
            'message' => $this->nodeReasonService->buildReasonMessage($reasonCode, $serverName),
            'heartbeat_status' => $heartbeatStatus,
            'heartbeat_fresh' => $heartbeatFresh,
            'probe' => [
                'url' => $probeUrl,
                'configured' => $probeConfigured,
                'state' => $probeState,
                'fail_count' => $probeFailCount,
                'threshold' => $probeThreshold,
                'last_checked_at' => $lastProbeCheckedAt,
                'last_ok_at' => $lastProbeOkAt,
                'last_error' => $lastProbeError,
                'result' => $probeResult,
            ],
        ];
    }

    private function clipProbeError(string $error): string
    {
        $trimmed = trim($error);
        if ($trimmed === '') {
            return 'Target unreachable';
        }

        return mb_strlen($trimmed) <= 500
            ? $trimmed
            : mb_substr($trimmed, 0, 497).'...';
    }
}
