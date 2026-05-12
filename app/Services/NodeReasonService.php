<?php

namespace App\Services;

use App\Models\MonitoredNode;
use App\Support\DateFormatter;

class NodeReasonService
{
    public function resolveServerName(?MonitoredNode $node, array $payload = [], array $summary = [], ?string $fallbackNodeId = null): string
    {
        $storedPayload = is_array($node?->last_payload_json) ? $node->last_payload_json : [];
        $host = is_array($summary['host'] ?? null) ? $summary['host'] : [];

        $candidates = [
            data_get($payload, 'server_name'),
            data_get($payload, 'node_name'),
            data_get($payload, 'host.hostname'),
            data_get($host, 'hostname'),
            data_get($storedPayload, 'server_name'),
            data_get($storedPayload, 'node_name'),
            data_get($storedPayload, 'host.hostname'),
            $node?->name,
            $fallbackNodeId,
            $node?->node_id,
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return 'unknown';
    }

    public function normalizeNodeStatus(string $status): string
    {
        return match (strtolower(trim($status))) {
            'ok', 'online', 'healthy', 'running' => 'ok',
            'degraded', 'warning' => 'degraded',
            'down', 'offline', 'errored', 'stopped' => 'down',
            default => 'unknown',
        };
    }

    public function normalizeReasonCode(?string $reasonCode): string
    {
        $value = strtolower(trim((string) $reasonCode));

        return match ($value) {
            'healthy',
            'heartbeat_timeout',
            'heartbeat_reported_down',
            'heartbeat_reported_degraded',
            'isp_down',
            'probe_not_configured',
            'unknown' => $value,
            default => 'unknown',
        };
    }

    public function buildReasonMessage(string $reasonCode, string $serverName): string
    {
        $code = $this->normalizeReasonCode($reasonCode);

        return match ($code) {
            'healthy' => sprintf('Heartbeat %s dan probe domain internal terdeteksi normal.', $serverName),
            'heartbeat_timeout' => sprintf('Heartbeat %s tidak diterima dalam batas waktu. Server dinyatakan down.', $serverName),
            'heartbeat_reported_down' => sprintf('Heartbeat %s melaporkan status down.', $serverName),
            'heartbeat_reported_degraded' => sprintf('Heartbeat %s melaporkan status degraded.', $serverName),
            'probe_not_configured' => sprintf('URL probe untuk %s belum dikonfigurasi di external monitoring.', $serverName),
            'isp_down' => sprintf(
                'Tidak ditemukan informasi %s. Kemungkinan ISP down, server internal down, service heartbeat mati, atau external tidak menerima sinyal.',
                $serverName
            ),
            default => sprintf('Status %s belum dapat ditentukan secara pasti.', $serverName),
        };
    }

    public function buildIspRecoveredMessage(string $serverName): string
    {
        return sprintf('Akses domain internal %s sudah kembali normal.', $serverName);
    }

    public function resolveProbeUrl(string $nodeId): ?string
    {
        $map = config('monitoring.internal_probe_url_map', []);
        if (! is_array($map)) {
            return null;
        }

        $url = trim((string) ($map[$nodeId] ?? ''));
        return $url !== '' ? $url : null;
    }

    public function probeFailureThreshold(): int
    {
        return max(1, (int) config('monitoring.internal_probe_failure_threshold', 2));
    }

    public function connectivityFromNode(MonitoredNode $node): array
    {
        return [
            'probe_url' => $this->resolveProbeUrl((string) $node->node_id),
            'state' => $this->normalizeProbeState($node->probe_state),
            'fail_count' => (int) ($node->probe_fail_count ?? 0),
            'failure_threshold' => $this->probeFailureThreshold(),
            'last_checked_at' => DateFormatter::isoUtc($node->last_probe_checked_at),
            'last_ok_at' => DateFormatter::isoUtc($node->last_probe_ok_at),
            'last_error' => $node->last_probe_error,
        ];
    }

    public function normalizeProbeState(?string $state): string
    {
        $value = strtolower(trim((string) $state));

        return match ($value) {
            'reachable', 'unreachable', 'unconfigured', 'unknown' => $value,
            default => 'unknown',
        };
    }
}

