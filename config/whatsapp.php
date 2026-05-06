<?php

return [
    'enabled' => filter_var(env('WHATSAPP_ALERT_ENABLED', false), FILTER_VALIDATE_BOOL),
    'provider' => strtolower((string) env('WHATSAPP_PROVIDER', 'fonnte')),
    'reminder_interval_minutes' => (int) env('WHATSAPP_REMINDER_INTERVAL_MINUTES', 60),
    'timezone' => (string) env('WHATSAPP_TIMEZONE', 'Asia/Jakarta'),

    'fonnte' => [
        'api_url' => (string) env('FONNTE_API_URL', 'https://api.fonnte.com/send'),
        'token' => (string) env('FONNTE_TOKEN', ''),
        'target_group_id' => (string) env('FONNTE_TARGET_GROUP_ID', ''),
    ],
];
