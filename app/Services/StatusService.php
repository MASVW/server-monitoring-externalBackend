<?php

namespace App\Services;

use App\Models\MonitoredNode;
use App\Support\DateFormatter;
use Carbon\CarbonImmutable;

class StatusService
{
    public function getNodeStatus(string $nodeId): ?array
    {
        $node = MonitoredNode::query()->where('node_id', $nodeId)->first();

        if ($node === null) {
            return null;
        }

        $serverName = $this->resolveServerName($node);
        $effectiveStatus = $this->resolveEffectiveStatus($node);

        return [
            'node_id' => $node->node_id,
            'server_name' => $serverName,
            'status' => $effectiveStatus,
            'current_status' => $effectiveStatus,
            'last_heartbeat_at' => DateFormatter::isoUtc($node->last_heartbeat_at),
            'heartbeat_interval_seconds' => $node->heartbeat_interval_seconds,
            'timeout_threshold_seconds' => $node->timeout_threshold_seconds,
            'message' => $this->buildStatusMessage($serverName, $effectiveStatus),
            'summary' => $node->last_summary_json ?? [
                'host' => [],
                'services' => [],
            ],
        ];
    }

    private function resolveEffectiveStatus(MonitoredNode $node): string
    {
        if (! in_array($node->current_status, ['ok', 'degraded', 'down', 'unknown'], true)) {
            return 'unknown';
        }

        if ($node->last_heartbeat_at === null) {
            return 'unknown';
        }

        $timeoutThresholdSeconds = max(1, (int) ($node->timeout_threshold_seconds ?: 180));
        $elapsedSeconds = CarbonImmutable::now('UTC')->diffInSeconds($node->last_heartbeat_at, true);

        if ($elapsedSeconds > $timeoutThresholdSeconds) {
            return 'unknown';
        }

        return $node->current_status;
    }

    private function resolveServerName(MonitoredNode $node): string
    {
        $payload = is_array($node->last_payload_json) ? $node->last_payload_json : [];

        $candidates = [
            data_get($payload, 'server_name'),
            data_get($payload, 'node_name'),
            data_get($payload, 'host.hostname'),
            $node->name,
            $node->node_id,
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return $node->node_id;
    }

    private function buildStatusMessage(string $serverName, string $status): string
    {
        if ($status === 'unknown') {
            return sprintf(
                'Tidak ditemukan informasi %s. Kemungkinan ISP down, server internal down, service heartbeat mati, atau external tidak menerima sinyal.',
                $serverName
            );
        }

        return sprintf('Heartbeat %s terdeteksi normal.', $serverName);
    }
}
