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

        $nodeId = (string) ($payload['node_id'] ?? 'unknown');
        $node = MonitoredNode::query()->where('node_id', $nodeId)->first();
        $summary = $this->resolveSummary($payload, $node);

        $lines = [
            '[Server Monitoring Alert]',
            'Node: '.$nodeId,
            'Type: '.($payload['event_type'] ?? 'unknown'),
            'Status: '.($payload['from_status'] ?? 'unknown').' -> '.($payload['to_status'] ?? 'unknown'),
            'Message: '.($payload['message'] ?? '-'),
            'Last Heartbeat (UTC): '.(DateFormatter::isoUtc($node?->last_heartbeat_at) ?? 'never'),
        ];

        $lines = array_merge(
            $lines,
            $this->buildServiceDetailLines($summary),
            $this->buildProblemLines($summary),
            [
            'Time (UTC): '.now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
            ]
        );

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
        ];

        $lines = array_merge(
            $lines,
            $this->buildServiceDetailLines($summary),
            $this->buildProblemLines($summary),
            [
                'Time (UTC): '.now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
            ]
        );

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

    private function resolveSummary(array $payload, ?MonitoredNode $node): array
    {
        $summaryFromPayload = $payload['summary'] ?? null;
        if (is_array($summaryFromPayload)) {
            return $summaryFromPayload;
        }

        $summaryFromNode = $node?->last_summary_json;
        return is_array($summaryFromNode) ? $summaryFromNode : [];
    }

    private function buildServiceDetailLines(array $summary): array
    {
        $services = $summary['services']['list'] ?? [];
        if (! is_array($services)) {
            $services = [];
        }

        $grouped = [
            'online' => [],
            'degraded' => [],
            'down' => [],
            'unknown' => [],
        ];

        foreach ($services as $service) {
            if (! is_array($service)) {
                continue;
            }

            $name = trim((string) ($service['name'] ?? 'unknown-service'));
            if ($name === '') {
                $name = 'unknown-service';
            }

            $bucket = $this->normalizeServiceBucket((string) ($service['status'] ?? 'unknown'));
            $grouped[$bucket][] = $name;
        }

        $total = count($services);
        if ($total === 0) {
            return ['Services: no service list in latest payload'];
        }

        return [
            sprintf(
                'Services: total=%d, online=%d, degraded=%d, down=%d, unknown=%d',
                $total,
                count($grouped['online']),
                count($grouped['degraded']),
                count($grouped['down']),
                count($grouped['unknown'])
            ),
            'Online Services: '.$this->formatServiceNames($grouped['online']),
            'Degraded Services: '.$this->formatServiceNames($grouped['degraded']),
            'Down Services: '.$this->formatServiceNames($grouped['down']),
            'Unknown Services: '.$this->formatServiceNames($grouped['unknown']),
        ];
    }

    private function buildProblemLines(array $summary): array
    {
        $problems = $summary['problems'] ?? [];
        if (! is_array($problems) || count($problems) === 0) {
            return ['Problems: none'];
        }

        $lines = ['Problems:'];
        $max = 5;

        foreach (array_slice($problems, 0, $max) as $problem) {
            if (! is_array($problem)) {
                continue;
            }

            $type = trim((string) ($problem['type'] ?? 'unknown'));
            $target = trim((string) ($problem['target'] ?? 'unknown'));
            $reason = trim((string) ($problem['reason'] ?? 'n/a'));

            $lines[] = sprintf('- [%s] %s: %s', $type, $target, $reason);
        }

        if (count($problems) > $max) {
            $lines[] = sprintf('- and %d more problem(s)', count($problems) - $max);
        }

        return $lines;
    }

    private function normalizeServiceBucket(string $status): string
    {
        return match (strtolower(trim($status))) {
            'online', 'ok', 'running', 'healthy' => 'online',
            'degraded', 'warning' => 'degraded',
            'down', 'stopped', 'errored', 'offline' => 'down',
            default => 'unknown',
        };
    }

    private function formatServiceNames(array $names): string
    {
        if (count($names) === 0) {
            return '-';
        }

        $max = 8;
        $sliced = array_slice($names, 0, $max);
        $value = implode(', ', $sliced);

        if (count($names) > $max) {
            $value .= sprintf(' (+%d more)', count($names) - $max);
        }

        return $value;
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
