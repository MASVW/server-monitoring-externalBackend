<?php

return [
    'enabled' => filter_var(env('DISCORD_ALERT_ENABLED', false), FILTER_VALIDATE_BOOL),
    'bot_token' => env('DISCORD_BOT_TOKEN', ''),
    'channel_id' => env('DISCORD_CHANNEL_ID', ''),
    'alert_interval_seconds' => (int) env('DISCORD_ALERT_INTERVAL_SECONDS', 60),
    'api_base_url' => env('DISCORD_API_BASE_URL', 'https://discord.com/api/v10'),
];
