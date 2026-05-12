<?php

namespace App\Services;

use App\Models\MonitoredNode;
use App\Support\DateFormatter;
use Carbon\CarbonImmutable;

class StatusService
{
    public function __construct(
        private readonly NodeReasonService $nodeReasonService,
        private readonly NodeHealthDecisionService $nodeHealthDecisionService,
    ) {}

    public function getNodeStatus(string $nodeId): ?array
    {
        $node = MonitoredNode::query()->where('node_id', $nodeId)->first();

        if ($node === null) {
            return null;
        }

        $summary = is_array($node->last_summary_json) ? $node->last_summary_json : [];
        $serverName = $this->nodeReasonService->resolveServerName($node, summary: $summary);

        $decision = $this->nodeHealthDecisionService->evaluate(
            node: $node,
            now: CarbonImmutable::now('UTC'),
            probeResult: null,
            probeAttempted: false,
            strictProbeReachability: true
        );

        $effectiveStatus = (string) ($decision['status'] ?? 'unknown');
        $reasonCode = (string) ($decision['reason_code'] ?? 'unknown');
        $message = $this->nodeReasonService->buildReasonMessage($reasonCode, $serverName);

        return [
            'node_id' => $node->node_id,
            'server_name' => $serverName,
            'status' => $effectiveStatus,
            'current_status' => $effectiveStatus,
            'reason_code' => $reasonCode,
            'last_heartbeat_at' => DateFormatter::isoUtc($node->last_heartbeat_at),
            'heartbeat_interval_seconds' => $node->heartbeat_interval_seconds,
            'timeout_threshold_seconds' => $node->timeout_threshold_seconds,
            'message' => $message,
            'connectivity' => [
                'probe_url' => $decision['probe']['url'],
                'state' => $decision['probe']['state'],
                'fail_count' => (int) ($decision['probe']['fail_count'] ?? 0),
                'failure_threshold' => (int) ($decision['probe']['threshold'] ?? $this->nodeReasonService->probeFailureThreshold()),
                'last_checked_at' => DateFormatter::isoUtc($decision['probe']['last_checked_at']),
                'last_ok_at' => DateFormatter::isoUtc($decision['probe']['last_ok_at']),
                'last_error' => $decision['probe']['last_error'],
            ],
            'summary' => $summary !== [] ? $summary : [
                'host' => [],
                'services' => [],
            ],
        ];
    }
}
