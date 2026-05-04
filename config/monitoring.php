<?php

return [
    'node_statuses' => ['ok', 'degraded', 'down', 'unknown'],
    'incident_event_types' => ['degraded', 'down', 'recovered', 'heartbeat_received', 'timeout_detected'],
    'timeout_checker_interval_seconds' => (int) env('TIMEOUT_CHECKER_INTERVAL_SECONDS', 60),
    'admin_api_token' => env('ADMIN_API_TOKEN', ''),
];
