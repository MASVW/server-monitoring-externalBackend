<?php

namespace App\Services;

use App\Models\MonitoredNode;
use App\Support\DateFormatter;
use Illuminate\Support\Facades\Http;

class AlertService
{
    public function isDiscordConfigured(): bool
    {
        return config('discord.enabled')
            && config('discord.bot_token') !== ''
            && config('discord.channel_id') !== '';
    }

    public function sendAlert(array $payload): array
    {
        if (! $this->isDiscordConfigured()) {
            return ['sent' => false, 'reason' => 'discord_not_configured'];
        }

        $lines = [
            '[Server Monitoring Alert]',
            'Node: '.$payload['node_id'],
            'Type: '.$payload['event_type'],
            'Status: '.($payload['from_status'] ?? 'unknown').' -> '.$payload['to_status'],
            'Message: '.$payload['message'],
            'Time (UTC): '.now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
        ];

        $this->postDiscordMessage(implode("\n", $lines));

        return ['sent' => true];
    }

    public function sendReminderAlert(MonitoredNode $node): array
    {
        if (! $this->isDiscordConfigured()) {
            return ['sent' => false, 'reason' => 'discord_not_configured'];
        }

        $summary = $node->last_summary_json ?? [];
        $downServices = $this->countPotentialUnhealthyServices($summary);
        $statusAlertable = in_array($node->current_status, ['down', 'degraded'], true);

        if (! $statusAlertable && $downServices <= 0) {
            return ['sent' => false, 'reason' => 'status_not_alertable'];
        }

        $lines = [
            '[Server Monitoring Reminder]',
            'Node: '.$node->node_id,
            'Current Status: '.$node->current_status,
            'Last Heartbeat (UTC): '.(DateFormatter::isoUtc($node->last_heartbeat_at) ?? 'never'),
            'Timeout Threshold: '.$node->timeout_threshold_seconds.'s',
            'Potential Unhealthy Services: '.$downServices,
            'Time (UTC): '.now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
        ];

        $this->postDiscordMessage(implode("\n", $lines));

        return ['sent' => true];
    }

    public function sendTestAlert(): array
    {
        $lines = [
            '[Server Monitoring Test]',
            'If you can read this, Discord bot delivery is working.',
            'Time (UTC): '.now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
        ];

        $this->postDiscordMessage(implode("\n", $lines));

        return ['sent' => true];
    }

    private function countPotentialUnhealthyServices(array $summary): int
    {
        $byStatus = $summary['services']['by_status'] ?? [];
        $count = 0;

        foreach ($byStatus as $status => $total) {
            if (strtolower((string) $status) === 'online') {
                continue;
            }

            $count += (int) $total;
        }

        return $count;
    }

    private function postDiscordMessage(string $content): void
    {
        if (! $this->isDiscordConfigured()) {
            throw new \RuntimeException('Discord API is not configured');
        }

        $url = rtrim((string) config('discord.api_base_url'), '/').'/channels/'.config('discord.channel_id').'/messages';
        $token = trim((string) config('discord.bot_token'));

        // Discord bot REST API requires: Authorization: Bot <token>
        if (str_starts_with(strtolower($token), 'bot ')) {
            $token = trim(substr($token, 4));
        }

        $response = Http::withToken($token, 'Bot')
            ->acceptJson()
            ->post($url, [
                'content' => $this->clip($content),
            ]);

        if (! $response->successful()) {
            $body = $this->clip((string) $response->body(), 300);
            throw new \RuntimeException("Discord API error {$response->status()}: {$body}");
        }
    }

    private function clip(string $text, int $max = 1900): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max - 3).'...';
    }
}
