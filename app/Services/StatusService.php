<?php

namespace App\Services;

use App\Models\MonitoredNode;
use App\Support\DateFormatter;

class StatusService
{
    public function getNodeStatus(string $nodeId): ?array
    {
        $node = MonitoredNode::query()->where('node_id', $nodeId)->first();

        if ($node === null) {
            return null;
        }

        return [
            'node_id' => $node->node_id,
            'current_status' => $node->current_status,
            'last_heartbeat_at' => DateFormatter::isoUtc($node->last_heartbeat_at),
            'heartbeat_interval_seconds' => $node->heartbeat_interval_seconds,
            'timeout_threshold_seconds' => $node->timeout_threshold_seconds,
            'summary' => $node->last_summary_json ?? [
                'host' => [],
                'services' => [],
            ],
        ];
    }
}
