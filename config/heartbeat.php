<?php

$toBytes = static function (string $value): int {
    $trimmed = strtolower(trim($value));

    if (preg_match('/^(\d+)(b|kb|mb|gb)?$/', $trimmed, $matches) !== 1) {
        return 102400;
    }

    $size = (int) $matches[1];
    $unit = $matches[2] ?? 'b';

    return match ($unit) {
        'gb' => $size * 1024 * 1024 * 1024,
        'mb' => $size * 1024 * 1024,
        'kb' => $size * 1024,
        default => $size,
    };
};

return [
    'hmac_secret' => env('HEARTBEAT_HMAC_SECRET', ''),
    'allowed_drift_seconds' => (int) env('HEARTBEAT_ALLOWED_DRIFT_SECONDS', 300),
    'max_body_size' => env('HEARTBEAT_MAX_BODY_SIZE', '100kb'),
    'max_body_size_bytes' => $toBytes((string) env('HEARTBEAT_MAX_BODY_SIZE', '100kb')),
    'rate_limit_max' => (int) env('HEARTBEAT_RATE_LIMIT_MAX', 120),
];
