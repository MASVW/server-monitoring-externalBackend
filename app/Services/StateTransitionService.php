<?php

namespace App\Services;

class StateTransitionService
{
    public function resolveHeartbeatTransition(string $fromStatus, string $toStatus): ?array
    {
        if ($fromStatus === $toStatus) {
            return null;
        }

        if ($toStatus === 'ok' && in_array($fromStatus, ['unknown', 'down', 'degraded'], true)) {
            return [
                'event_type' => 'recovered',
                'message' => "Node recovered from {$fromStatus} to ok",
            ];
        }

        if ($toStatus === 'degraded' && $fromStatus === 'ok') {
            return [
                'event_type' => 'degraded',
                'message' => 'Node transitioned from ok to degraded',
            ];
        }

        if ($toStatus === 'down' && $fromStatus !== 'down') {
            return [
                'event_type' => 'down',
                'message' => "Node transitioned from {$fromStatus} to down",
            ];
        }

        return null;
    }

    public function resolveTimeoutTransition(string $fromStatus): ?array
    {
        if ($fromStatus === 'down') {
            return null;
        }

        if (in_array($fromStatus, ['ok', 'degraded'], true)) {
            return [
                'event_type' => 'timeout_detected',
                'to_status' => 'down',
                'message' => 'Timeout detected: heartbeat missing beyond threshold',
            ];
        }

        return null;
    }
}
