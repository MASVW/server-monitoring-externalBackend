<?php

return [
    'node_statuses' => ['ok', 'degraded', 'down', 'unknown'],
    'incident_event_types' => [
        'degraded',
        'down',
        'recovered',
        'heartbeat_received',
        'timeout_detected',
        'isp_unreachable',
        'isp_recovered',
    ],
    'timeout_checker_interval_seconds' => (int) env('TIMEOUT_CHECKER_INTERVAL_SECONDS', 60),
    'internal_probe_url_map' => (function () {
        $raw = env('INTERNAL_PROBE_URL_MAP', '{}');

        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    })(),
    'internal_probe_timeout_seconds' => (int) env('INTERNAL_PROBE_TIMEOUT_SECONDS', 5),
    'internal_probe_failure_threshold' => max(1, (int) env('INTERNAL_PROBE_FAILURE_THRESHOLD', 2)),
    'admin_api_token' => env('ADMIN_API_TOKEN', ''),
];
