<?php

namespace App\Services;

use App\Models\MonitoredNode;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AlertService
{
    private const COLOR_GREEN = 0x22C55E;

    private const COLOR_ORANGE = 0xF59E0B;

    private const COLOR_RED = 0xEF4444;

    private const COLOR_GREY = 0x6B7280;

    private const MAX_EMBED_FIELDS = 25;

    private const MAX_EMBED_TOTAL_CHARS = 6000;

    private const MAX_EMBED_TITLE_CHARS = 256;

    private const MAX_EMBED_DESCRIPTION_CHARS = 4096;

    private const MAX_EMBED_FIELD_NAME_CHARS = 256;

    private const MAX_EMBED_FIELD_VALUE_CHARS = 1024;

    private const MAX_EMBED_FOOTER_CHARS = 2048;

    public function __construct(
        private readonly NodeReasonService $nodeReasonService,
        private readonly WhatsAppAlertService $whatsAppAlertService,
    ) {}

    public function isDiscordConfigured(): bool
    {
        return config('discord.enabled')
            && config('discord.bot_token') !== ''
            && config('discord.channel_id') !== '';
    }

    public function sendAlert(array $payload): array
    {
        $nodeId = (string) ($payload['node_id'] ?? 'unknown');
        $node = MonitoredNode::query()->where('node_id', $nodeId)->first();
        $summary = $this->resolveSummary($payload, $node);

        $discordResult = ['sent' => false, 'reason' => 'discord_not_configured'];
        if ($this->isDiscordConfigured()) {
            try {
                $this->postDiscordEmbed($this->buildAlertEmbed($payload, $node, $summary));
                $discordResult = ['sent' => true];
            } catch (\Throwable $exception) {
                $discordResult = ['sent' => false, 'reason' => 'discord_send_failed'];
                Log::error('Discord alert dispatch failed', [
                    'node_id' => $nodeId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $whatsAppResult = ['sent' => false, 'reason' => 'whatsapp_not_dispatched'];
        try {
            $whatsAppResult = $this->dispatchWhatsAppForTransition($payload, $node, $summary);
        } catch (\Throwable $exception) {
            Log::error('WhatsApp alert dispatch failed', [
                'node_id' => $nodeId,
                'error' => $exception->getMessage(),
            ]);
        }

        return [
            'sent' => (bool) ($discordResult['sent'] ?? false) || (bool) ($whatsAppResult['sent'] ?? false),
            'channels' => [
                'discord' => $discordResult,
                'whatsapp' => $whatsAppResult,
            ],
        ];
    }

    public function sendReminderAlert(MonitoredNode $node): array
    {
        if (! $this->isDiscordConfigured()) {
            return ['sent' => false, 'reason' => 'discord_not_configured'];
        }

        $summary = is_array($node->last_summary_json) ? $node->last_summary_json : [];
        $serviceSummary = $this->summarizeServices($summary);

        $statusAlertable = in_array($this->normalizeServiceBucket((string) $node->current_status), ['down', 'degraded'], true);
        if (! $statusAlertable && ($serviceSummary['potential_unhealthy'] ?? 0) <= 0) {
            return ['sent' => false, 'reason' => 'status_not_alertable'];
        }

        $this->postDiscordEmbed($this->buildReminderEmbed($node, $summary));

        return ['sent' => true];
    }

    public function sendTestAlert(): array
    {
        $this->postDiscordEmbed($this->buildTestEmbed());

        return ['sent' => true];
    }

    private function buildAlertEmbed(array $payload, ?MonitoredNode $node, array $summary): array
    {
        $status = $this->normalizeServiceBucket((string) ($payload['to_status'] ?? $node?->current_status ?? 'unknown'));
        $nodeId = (string) ($payload['node_id'] ?? $node?->node_id ?? 'unknown');
        $reasonCode = $this->resolveReasonCode($payload, $node);
        $incidentMessage = $this->resolveIncidentMessage($payload, $node, $summary, $status, $reasonCode);
        $eventType = strtolower(trim((string) ($payload['event_type'] ?? '')));

        $serviceSummary = $this->summarizeServices($summary);
        $unhealthyCount = (int) ($serviceSummary['potential_unhealthy'] ?? 0);

        $description = $unhealthyCount > 0
            ? sprintf('%d service terdeteksi tidak sehat pada node %s.', $unhealthyCount, $nodeId)
            : sprintf('Node %s berada pada status %s dan membutuhkan pengecekan.', $nodeId, $status);
        if ($reasonCode === 'isp_down') {
            $description = sprintf(
                'Heartbeat diterima dari node %s, namun domain internal tidak reachable dari external.',
                $nodeId
            );
        } elseif ($eventType === 'timeout_detected' && $incidentMessage !== null) {
            $description = $incidentMessage;
        }

        $fields = [
            $this->buildNodeField(
                nodeId: $nodeId,
                status: $status,
                node: $node,
                summary: $summary,
                lastHeartbeat: $payload['last_heartbeat_at'] ?? $node?->last_heartbeat_at
            ),
            $this->buildIncidentMessageField($incidentMessage),
            $this->buildConnectivityField($payload, $node),
            $this->buildConditionField($serviceSummary),
            $this->buildProblemServicesField($serviceSummary),
            $this->buildNormalServicesField($serviceSummary),
            $this->buildResourceField(is_array($summary['host'] ?? null) ? $summary['host'] : []),
            $this->buildProblemsField($summary),
            [
                'name' => '🕒 Alert Time',
                'value' => $this->formatHumanWibDateTime(now('UTC')),
                'inline' => false,
            ],
        ];

        return $this->sanitizeEmbed([
            'title' => '🚨 SERVER MONITORING ALERT — '.strtoupper($status),
            'description' => $description,
            'color' => $this->statusColor($status),
            'fields' => $fields,
            'footer' => [
                'text' => 'Server Monitoring',
            ],
            'timestamp' => now('UTC')->toIso8601String(),
        ]);
    }

    private function buildReminderEmbed(MonitoredNode $node, array $summary): array
    {
        $status = $this->normalizeServiceBucket((string) ($node->current_status ?? 'unknown'));
        $serviceSummary = $this->summarizeServices($summary);
        $unhealthyCount = (int) ($serviceSummary['potential_unhealthy'] ?? 0);
        $reasonCode = $this->nodeReasonService->normalizeReasonCode((string) ($node->reason_code ?? 'unknown'));
        $incidentMessage = $this->resolveIncidentMessage(
            ['reason_code' => $reasonCode],
            $node,
            $summary,
            $status,
            $reasonCode
        );

        $description = $unhealthyCount > 0
            ? sprintf('%d service masih belum sehat pada node %s.', $unhealthyCount, $node->node_id)
            : sprintf('Node %s berada pada status %s dan membutuhkan pengecekan.', $node->node_id, $status);
        if ($reasonCode === 'isp_down') {
            $description = sprintf(
                'Heartbeat diterima dari node %s, namun domain internal masih belum reachable dari external.',
                $node->node_id
            );
        } elseif ($incidentMessage !== null) {
            $description = $incidentMessage;
        }

        $fields = [
            $this->buildNodeField(
                nodeId: $node->node_id,
                status: $status,
                node: $node,
                summary: $summary,
                lastHeartbeat: $node->last_heartbeat_at
            ),
            $this->buildIncidentMessageField($incidentMessage),
            $this->buildConnectivityField([], $node),
            $this->buildConditionField($serviceSummary),
            $this->buildProblemServicesField($serviceSummary),
            $this->buildNormalServicesField($serviceSummary),
            $this->buildResourceField(is_array($summary['host'] ?? null) ? $summary['host'] : []),
            $this->buildProblemsField($summary),
            [
                'name' => '🕒 Alert Time',
                'value' => $this->formatHumanWibDateTime(now('UTC')),
                'inline' => false,
            ],
        ];

        return $this->sanitizeEmbed([
            'title' => '🔁 SERVER MONITORING REMINDER — '.strtoupper($status),
            'description' => $description,
            'color' => $this->statusColor($status),
            'fields' => $fields,
            'footer' => [
                'text' => 'Server Monitoring',
            ],
            'timestamp' => now('UTC')->toIso8601String(),
        ]);
    }

    private function buildTestEmbed(): array
    {
        return $this->sanitizeEmbed([
            'title' => '✅ SERVER MONITORING TEST',
            'description' => 'Discord bot delivery is working.',
            'color' => self::COLOR_GREEN,
            'fields' => [
                [
                    'name' => '🕒 Test Time',
                    'value' => $this->formatHumanWibDateTime(now('UTC')),
                    'inline' => false,
                ],
            ],
            'footer' => [
                'text' => 'Server Monitoring',
            ],
            'timestamp' => now('UTC')->toIso8601String(),
        ]);
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

    private function summarizeServices(array $summary): array
    {
        $services = data_get($summary, 'services.list');
        if (! is_array($services)) {
            $services = [];
        }

        $counts = [
            'online' => 0,
            'degraded' => 0,
            'down' => 0,
            'unknown' => 0,
        ];

        $groupedNames = [
            'online' => [],
            'degraded' => [],
            'down' => [],
            'unknown' => [],
        ];

        $problemServices = [];

        foreach ($services as $service) {
            if (! is_array($service)) {
                continue;
            }

            $name = trim((string) ($service['name'] ?? 'unknown-service'));
            if ($name === '') {
                $name = 'unknown-service';
            }

            $bucket = $this->normalizeServiceBucket((string) ($service['status'] ?? 'unknown'));
            $counts[$bucket]++;
            $groupedNames[$bucket][] = $name;

            if ($bucket !== 'online') {
                $problemServices[] = [
                    'name' => $name,
                    'status' => $bucket,
                ];
            }
        }

        if (count($services) === 0) {
            $byStatus = data_get($summary, 'services.by_status', []);
            if (is_array($byStatus)) {
                foreach ($byStatus as $status => $total) {
                    $bucket = $this->normalizeServiceBucket((string) $status);
                    $counts[$bucket] += max(0, (int) $total);
                }
            }
        }

        $total = array_sum($counts);
        $potentialUnhealthy = $counts['degraded'] + $counts['down'] + $counts['unknown'];

        return [
            'total' => $total,
            'counts' => $counts,
            'grouped_names' => $groupedNames,
            'problem_services' => $problemServices,
            'potential_unhealthy' => $potentialUnhealthy,
        ];
    }

    private function summarizeDisks(array $host): array
    {
        $rawDisk = $host['disk'] ?? [];
        if (! is_array($rawDisk)) {
            return [];
        }

        $items = [];

        if (array_is_list($rawDisk)) {
            foreach ($rawDisk as $disk) {
                if (is_array($disk)) {
                    $items[] = $this->normalizeDiskItem($disk, null);
                }
            }
        } else {
            $looksLikeSingleDisk = isset($rawDisk['mountPoint'])
                || isset($rawDisk['mount'])
                || isset($rawDisk['path'])
                || isset($rawDisk['usedPercent'])
                || isset($rawDisk['usage_percent'])
                || isset($rawDisk['totalBytes'])
                || isset($rawDisk['sizeBytes']);

            if ($looksLikeSingleDisk) {
                $items[] = $this->normalizeDiskItem($rawDisk, '/');
            } else {
                foreach ($rawDisk as $mountKey => $disk) {
                    if (! is_array($disk)) {
                        continue;
                    }

                    $items[] = $this->normalizeDiskItem($disk, is_string($mountKey) ? $mountKey : null);
                }
            }
        }

        $items = array_values(array_filter($items, static fn (array $item): bool => $item['mount'] !== ''));

        if ($items === []) {
            return [];
        }

        $technicalMounts = ['/etc/hosts', '/etc/hostname', '/etc/resolv.conf'];
        $hasNonTechnical = false;
        foreach ($items as $item) {
            if (! in_array($item['mount'], $technicalMounts, true)) {
                $hasNonTechnical = true;
                break;
            }
        }

        if ($hasNonTechnical) {
            $items = array_values(array_filter(
                $items,
                static fn (array $item): bool => ! in_array($item['mount'], $technicalMounts, true)
            ));
        }

        usort($items, function (array $left, array $right): int {
            $leftPriority = $this->diskPriority($left['mount']);
            $rightPriority = $this->diskPriority($right['mount']);

            if ($leftPriority !== $rightPriority) {
                return $leftPriority <=> $rightPriority;
            }

            return strcmp($left['mount'], $right['mount']);
        });

        return array_slice($items, 0, 3);
    }

    private function normalizeDiskItem(array $disk, ?string $fallbackMount): array
    {
        $mount = trim((string) (
            $disk['mountPoint']
            ?? $disk['mount']
            ?? $disk['path']
            ?? $disk['filesystem']
            ?? $fallbackMount
            ?? ''
        ));

        $percent = $this->firstNumericFromPaths($disk, [
            'usedPercent',
            'usage_percent',
            'usagePercent',
            'percent',
            'percentage',
        ]);

        $usedGb = $this->firstNumericFromPaths($disk, ['usedGb', 'used_gb']);
        if ($usedGb === null) {
            $usedGb = $this->formatBytesToGb($this->firstNumericFromPaths($disk, ['usedBytes', 'used_bytes', 'used']));
        }

        $totalGb = $this->firstNumericFromPaths($disk, ['totalGb', 'total_gb']);
        if ($totalGb === null) {
            $totalGb = $this->formatBytesToGb($this->firstNumericFromPaths($disk, ['sizeBytes', 'totalBytes', 'total_bytes', 'size', 'total']));
        }

        if ($percent === null && $usedGb !== null && $totalGb !== null && $totalGb > 0) {
            $percent = ($usedGb / $totalGb) * 100;
        }

        return [
            'mount' => $mount,
            'percent' => $percent,
            'used_gb' => $usedGb,
            'total_gb' => $totalGb,
        ];
    }

    private function diskPriority(string $mount): int
    {
        return match ($mount) {
            '/' => 0,
            '/root/.pm2' => 1,
            default => 10,
        };
    }

    private function buildNodeField(string $nodeId, string $status, ?MonitoredNode $node, array $summary, mixed $lastHeartbeat): array
    {
        $host = is_array($summary['host'] ?? null) ? $summary['host'] : [];
        $hostname = trim((string) ($host['hostname'] ?? '-'));
        $platform = trim((string) ($host['platform'] ?? '-'));
        $uptime = $this->firstNumericFromPaths($host, ['uptime', 'uptimeSeconds', 'uptime_seconds']);
        $timeoutThreshold = (int) ($node?->timeout_threshold_seconds ?? 180);

        $value = implode("\n", [
            '• ID: '.$nodeId,
            '• Hostname: '.($hostname !== '' ? $hostname : '-'),
            '• Platform: '.($platform !== '' ? $platform : '-'),
            '• Status: '.$this->statusEmoji($status).' '.strtoupper($status),
            '• Last Heartbeat: '.$this->formatHumanWibDateTime($lastHeartbeat),
            '• Timeout Threshold: '.$timeoutThreshold.' detik',
            '• Uptime: '.($uptime !== null ? $this->formatDuration((int) round($uptime)) : '-'),
        ]);

        return [
            'name' => '🖥️ Node',
            'value' => $this->clipFieldValue($value),
            'inline' => false,
        ];
    }

    private function buildConditionField(array $serviceSummary): array
    {
        $counts = $serviceSummary['counts'] ?? [
            'online' => 0,
            'degraded' => 0,
            'down' => 0,
            'unknown' => 0,
        ];

        $value = implode("\n", [
            '• Total Service: '.(int) ($serviceSummary['total'] ?? 0),
            '• Online: 🟢 '.(int) ($counts['online'] ?? 0),
            '• Degraded: 🟡 '.(int) ($counts['degraded'] ?? 0),
            '• Down: 🔴 '.(int) ($counts['down'] ?? 0),
            '• Unknown: ⚪ '.(int) ($counts['unknown'] ?? 0),
            '• Potential Unhealthy Services: '.(int) ($serviceSummary['potential_unhealthy'] ?? 0),
        ]);

        return [
            'name' => '📌 Ringkasan Kondisi',
            'value' => $this->clipFieldValue($value),
            'inline' => false,
        ];
    }

    private function buildProblemServicesField(array $serviceSummary): array
    {
        $problemServices = $serviceSummary['problem_services'] ?? [];
        if (! is_array($problemServices) || $problemServices === []) {
            $fallback = ((int) ($serviceSummary['potential_unhealthy'] ?? 0)) > 0
                ? 'Ada layanan tidak sehat, tetapi detail nama service tidak tersedia.'
                : 'Tidak ada layanan bermasalah.';

            return [
                'name' => '🔴 Layanan Bermasalah',
                'value' => $fallback,
                'inline' => false,
            ];
        }

        $lines = [];
        foreach ($problemServices as $service) {
            if (! is_array($service)) {
                continue;
            }

            $lines[] = sprintf(
                '• %s — status: %s',
                (string) ($service['name'] ?? 'unknown-service'),
                (string) ($service['status'] ?? 'unknown')
            );
        }

        if ($lines === []) {
            $lines[] = 'Tidak ada layanan bermasalah.';
        }

        return [
            'name' => '🔴 Layanan Bermasalah',
            'value' => $this->clipFieldValue(implode("\n", $lines)),
            'inline' => false,
        ];
    }

    private function buildNormalServicesField(array $serviceSummary): array
    {
        $online = $serviceSummary['grouped_names']['online'] ?? [];
        if (! is_array($online) || $online === []) {
            return [
                'name' => '🟢 Layanan Normal',
                'value' => 'Tidak ada layanan online.',
                'inline' => false,
            ];
        }

        $max = 8;
        $shown = array_slice($online, 0, $max);
        $remaining = count($online) - count($shown);

        $value = implode(', ', $shown);
        if ($remaining > 0) {
            $value .= "\n+{$remaining} layanan lainnya masih online";
        }

        return [
            'name' => '🟢 Layanan Normal',
            'value' => $this->clipFieldValue($value),
            'inline' => false,
        ];
    }

    private function buildIncidentMessageField(?string $message): array
    {
        $value = trim((string) $message);
        if ($value === '') {
            $value = '-';
        }

        return [
            'name' => '⚠️ Keterangan',
            'value' => $this->clipFieldValue($value),
            'inline' => false,
        ];
    }

    private function buildConnectivityField(array $payload, ?MonitoredNode $node): array
    {
        $connectivity = $this->resolveConnectivity($payload, $node);
        $probeState = strtolower(trim((string) ($connectivity['state'] ?? 'unknown')));
        $probeStateLabel = match ($probeState) {
            'reachable' => '🟢 REACHABLE',
            'unreachable' => '🔴 UNREACHABLE',
            'unconfigured' => '⚪ UNCONFIGURED',
            default => '⚪ UNKNOWN',
        };

        $value = implode("\n", [
            '• Probe URL: '.($connectivity['probe_url'] ?: '-'),
            '• State: '.$probeStateLabel,
            '• Fail Count: '.(int) ($connectivity['fail_count'] ?? 0).' / '.(int) ($connectivity['failure_threshold'] ?? 0),
            '• Last Checked: '.$this->formatHumanWibDateTime($connectivity['last_checked_at'] ?? null),
            '• Last OK: '.$this->formatHumanWibDateTime($connectivity['last_ok_at'] ?? null),
            '• Last Error: '.(trim((string) ($connectivity['last_error'] ?? '')) ?: '-'),
        ]);

        return [
            'name' => '🌐 Connectivity Probe',
            'value' => $this->clipFieldValue($value),
            'inline' => false,
        ];
    }

    private function buildResourceField(array $host): array
    {
        $cpuPercent = $this->firstNumericFromPaths($host, [
            'cpu.loadPercent',
            'cpu.usage_percent',
            'cpu.usagePercent',
            'cpu.percent',
            'cpu.percentage',
            'cpu.total_usage_percent',
            'cpu.totalUsagePercent',
        ]);
        $cpuCores = $this->firstNumericFromPaths($host, ['cpu.cores']);

        $memoryPercent = $this->firstNumericFromPaths($host, [
            'memory.usedPercent',
            'memory.usage_percent',
            'memory.usagePercent',
            'memory.percent',
            'memory.percentage',
        ]);

        $memoryUsedGb = $this->formatBytesToGb($this->firstNumericFromPaths($host, ['memory.usedBytes', 'memory.used_bytes', 'memory.used']));
        $memoryTotalGb = $this->formatBytesToGb($this->firstNumericFromPaths($host, ['memory.totalBytes', 'memory.total_bytes', 'memory.total']));

        if ($memoryPercent === null && $memoryUsedGb !== null && $memoryTotalGb !== null && $memoryTotalGb > 0) {
            $memoryPercent = ($memoryUsedGb / $memoryTotalGb) * 100;
        }

        $lines = [];

        $cpuLine = '• CPU: '.$this->healthEmoji($cpuPercent).' '.$this->formatPercent($cpuPercent);
        if ($cpuCores !== null) {
            $cpuLine .= ' / '.(int) round($cpuCores).' cores';
        }
        $lines[] = $cpuLine;

        $lines[] = '• Memory: '.$this->healthEmoji($memoryPercent).' '.$this->formatPercent($memoryPercent)
            .' — '.$this->formatGb($memoryUsedGb).' / '.$this->formatGb($memoryTotalGb);

        $disks = $this->summarizeDisks($host);
        if ($disks === []) {
            $lines[] = '• Disk: data tidak tersedia';
        } else {
            foreach ($disks as $disk) {
                $lines[] = sprintf(
                    '• Disk %s: %s %s — %s / %s',
                    $disk['mount'],
                    $this->healthEmoji($disk['percent']),
                    $this->formatPercent($disk['percent']),
                    $this->formatGb($disk['used_gb']),
                    $this->formatGb($disk['total_gb'])
                );
            }
        }

        return [
            'name' => '📊 Resource Server',
            'value' => $this->clipFieldValue(implode("\n", $lines)),
            'inline' => false,
        ];
    }

    private function buildProblemsField(array $summary): array
    {
        $problems = $summary['problems'] ?? [];
        if (! is_array($problems) || count($problems) === 0) {
            return [
                'name' => '✅ Problems',
                'value' => 'Tidak ada problem detail dari agent.',
                'inline' => false,
            ];
        }

        $lines = [];
        $max = 5;

        foreach (array_slice($problems, 0, $max) as $problem) {
            if (! is_array($problem)) {
                continue;
            }

            $type = trim((string) ($problem['type'] ?? 'unknown'));
            $target = trim((string) ($problem['target'] ?? 'unknown'));
            $reason = trim((string) ($problem['reason'] ?? 'n/a'));

            $lines[] = sprintf('• [%s] %s: %s', $type, $target, $reason);
        }

        if (count($problems) > $max) {
            $lines[] = '+'.(count($problems) - $max).' problem tambahan';
        }

        if ($lines === []) {
            $lines[] = 'Tidak ada problem detail dari agent.';
        }

        return [
            'name' => '✅ Problems',
            'value' => $this->clipFieldValue(implode("\n", $lines)),
            'inline' => false,
        ];
    }

    private function postDiscordEmbed(array $embed): void
    {
        if (! $this->isDiscordConfigured()) {
            throw new \RuntimeException('Discord API is not configured');
        }

        $url = rtrim((string) config('discord.api_base_url'), '/').'/channels/'.config('discord.channel_id').'/messages';
        $token = trim((string) config('discord.bot_token'));

        if (str_starts_with(strtolower($token), 'bot ')) {
            $token = trim(substr($token, 4));
        }

        $response = Http::withToken($token, 'Bot')
            ->acceptJson()
            ->post($url, [
                'embeds' => [$this->sanitizeEmbed($embed)],
                'allowed_mentions' => [
                    'parse' => [],
                ],
            ]);

        if (! $response->successful()) {
            $body = $this->clipText((string) $response->body(), 300);
            throw new \RuntimeException("Discord API error {$response->status()}: {$body}");
        }
    }

    private function statusEmoji(string $status): string
    {
        return match ($this->normalizeServiceBucket($status)) {
            'online' => '🟢',
            'degraded' => '🟡',
            'down' => '🔴',
            default => '⚪',
        };
    }

    private function statusColor(string $status): int
    {
        return match ($this->normalizeServiceBucket($status)) {
            'online' => self::COLOR_GREEN,
            'degraded' => self::COLOR_ORANGE,
            'down' => self::COLOR_RED,
            default => self::COLOR_GREY,
        };
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

    private function formatHumanWibDateTime(mixed $value): string
    {
        if (! $value) {
            return 'never';
        }

        try {
            return Carbon::parse($value)
                ->timezone('Asia/Jakarta')
                ->locale('id')
                ->translatedFormat('d F Y, h:i A').' WIB';
        } catch (\Throwable) {
            return '-';
        }
    }

    private function formatBytesToGb(mixed $bytes): ?float
    {
        $number = $this->toFloat($bytes);

        if ($number === null) {
            return null;
        }

        return $number / (1024 * 1024 * 1024);
    }

    private function formatGb(?float $value): string
    {
        if ($value === null) {
            return '-';
        }

        return number_format($value, 2, '.', '').' GB';
    }

    private function formatPercent(?float $percent): string
    {
        if ($percent === null) {
            return '-';
        }

        return number_format($percent, 1, '.', '').'%';
    }

    private function healthEmoji(?float $percent): string
    {
        if ($percent === null) {
            return '⚪';
        }

        if ($percent >= 85) {
            return '🔴';
        }

        if ($percent >= 70) {
            return '🟡';
        }

        return '🟢';
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.' detik';
        }

        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return $days.' hari '.$hours.' jam';
        }

        if ($hours > 0) {
            return $hours.' jam '.$minutes.' menit';
        }

        return $minutes.' menit';
    }

    private function firstNumericFromPaths(array $source, array $paths): ?float
    {
        foreach ($paths as $path) {
            $number = $this->toFloat(data_get($source, $path));
            if ($number !== null) {
                return $number;
            }
        }

        return null;
    }

    private function toFloat(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (is_numeric($trimmed)) {
            return (float) $trimmed;
        }

        if (preg_match('/(-?\d+(?:\.\d+)?)/', str_replace(',', '.', $trimmed), $matches) === 1) {
            return (float) $matches[1];
        }

        return null;
    }

    private function sanitizeEmbed(array $embed): array
    {
        $embed['title'] = $this->clipText((string) ($embed['title'] ?? ''), self::MAX_EMBED_TITLE_CHARS);
        $embed['description'] = $this->clipText((string) ($embed['description'] ?? ''), self::MAX_EMBED_DESCRIPTION_CHARS);

        if (isset($embed['footer']['text'])) {
            $embed['footer']['text'] = $this->clipText((string) $embed['footer']['text'], self::MAX_EMBED_FOOTER_CHARS);
        }

        $fields = is_array($embed['fields'] ?? null) ? $embed['fields'] : [];
        $normalizedFields = [];

        foreach (array_slice($fields, 0, self::MAX_EMBED_FIELDS) as $field) {
            if (! is_array($field)) {
                continue;
            }

            $normalizedFields[] = [
                'name' => $this->clipText((string) ($field['name'] ?? '-'), self::MAX_EMBED_FIELD_NAME_CHARS),
                'value' => $this->clipFieldValue((string) ($field['value'] ?? '-')),
                'inline' => false,
            ];
        }

        $embed['fields'] = $normalizedFields;

        while ($this->embedCharacterCount($embed) > self::MAX_EMBED_TOTAL_CHARS && count($embed['fields']) > 0) {
            $index = count($embed['fields']) - 1;
            $current = (string) ($embed['fields'][$index]['value'] ?? '');

            if (mb_strlen($current) <= 32) {
                array_pop($embed['fields']);
                continue;
            }

            $embed['fields'][$index]['value'] = $this->clipFieldValue($current, mb_strlen($current) - 32);
        }

        if ($this->embedCharacterCount($embed) > self::MAX_EMBED_TOTAL_CHARS) {
            $overflow = $this->embedCharacterCount($embed) - self::MAX_EMBED_TOTAL_CHARS;
            $target = max(64, self::MAX_EMBED_DESCRIPTION_CHARS - $overflow);
            $embed['description'] = $this->clipText((string) ($embed['description'] ?? ''), $target);
        }

        return $embed;
    }

    private function embedCharacterCount(array $embed): int
    {
        $total = 0;

        $total += mb_strlen((string) ($embed['title'] ?? ''));
        $total += mb_strlen((string) ($embed['description'] ?? ''));
        $total += mb_strlen((string) ($embed['footer']['text'] ?? ''));

        $fields = is_array($embed['fields'] ?? null) ? $embed['fields'] : [];
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $total += mb_strlen((string) ($field['name'] ?? ''));
            $total += mb_strlen((string) ($field['value'] ?? ''));
        }

        return $total;
    }

    private function clipText(string $text, int $max): string
    {
        if ($max <= 0) {
            return '';
        }

        if (mb_strlen($text) <= $max) {
            return $text;
        }

        if ($max <= 3) {
            return mb_substr($text, 0, $max);
        }

        return rtrim(mb_substr($text, 0, $max - 3)).'...';
    }

    private function clipFieldValue(string $value, ?int $max = null): string
    {
        return $this->clipText($value, $max ?? self::MAX_EMBED_FIELD_VALUE_CHARS);
    }

    private function dispatchWhatsAppForTransition(array $payload, ?MonitoredNode $node, array $summary): array
    {
        $status = $this->normalizeNodeStatusForIncident((string) ($payload['to_status'] ?? $node?->current_status ?? 'unknown'));
        $nodeId = (string) ($payload['node_id'] ?? $node?->node_id ?? 'unknown');
        $serverName = $this->nodeReasonService->resolveServerName($node, $payload, $summary, $nodeId);
        $reasonCode = $this->resolveReasonCode($payload, $node);
        $message = trim((string) ($payload['message'] ?? $this->nodeReasonService->buildReasonMessage($reasonCode, $serverName)));
        $connectivity = $this->resolveConnectivity($payload, $node);

        $context = [
            'node_id' => $nodeId,
            'server_name' => $serverName,
            'status' => $status,
            'previous_status' => (string) ($payload['from_status'] ?? 'unknown'),
            'reason_code' => $reasonCode,
            'summary' => $summary,
            'timeout_threshold_seconds' => (int) ($node?->timeout_threshold_seconds ?? 180),
            'last_heartbeat_at' => $node?->last_heartbeat_at,
            'event_type' => (string) ($payload['event_type'] ?? 'unknown'),
            'message' => $message,
            'connectivity' => $connectivity,
        ];

        if (in_array($status, ['down', 'degraded'], true)) {
            return $this->whatsAppAlertService->sendIncidentAlert($context);
        }

        if ($status === 'ok') {
            return $this->whatsAppAlertService->sendRecoveryAlert($context);
        }

        return ['sent' => false, 'reason' => 'status_not_alertable'];
    }

    private function normalizeNodeStatusForIncident(string $status): string
    {
        return $this->nodeReasonService->normalizeNodeStatus($status);
    }

    private function resolveIncidentMessage(
        array $payload,
        ?MonitoredNode $node,
        array $summary,
        string $status,
        ?string $reasonCode = null
    ): ?string
    {
        $message = trim((string) ($payload['message'] ?? ''));
        if ($message !== '') {
            return $message;
        }

        $normalizedReasonCode = $this->nodeReasonService->normalizeReasonCode(
            $reasonCode ?? (string) ($payload['reason_code'] ?? $node?->reason_code ?? 'unknown')
        );

        if ($normalizedReasonCode === 'unknown' && ! in_array($status, ['down', 'unknown'], true)) {
            return null;
        }

        $nodeId = (string) ($payload['node_id'] ?? $node?->node_id ?? 'unknown');
        $serverName = $this->nodeReasonService->resolveServerName($node, $payload, $summary, $nodeId);

        return $this->nodeReasonService->buildReasonMessage($normalizedReasonCode, $serverName);
    }

    private function resolveReasonCode(array $payload, ?MonitoredNode $node): string
    {
        return $this->nodeReasonService->normalizeReasonCode(
            (string) ($payload['reason_code'] ?? $node?->reason_code ?? 'unknown')
        );
    }

    private function resolveConnectivity(array $payload, ?MonitoredNode $node): array
    {
        $candidate = $payload['connectivity'] ?? null;
        if (is_array($candidate)) {
            return [
                'probe_url' => trim((string) ($candidate['probe_url'] ?? '')),
                'state' => trim((string) ($candidate['state'] ?? 'unknown')),
                'fail_count' => (int) ($candidate['fail_count'] ?? 0),
                'failure_threshold' => (int) ($candidate['failure_threshold'] ?? $this->nodeReasonService->probeFailureThreshold()),
                'last_checked_at' => $candidate['last_checked_at'] ?? null,
                'last_ok_at' => $candidate['last_ok_at'] ?? null,
                'last_error' => $candidate['last_error'] ?? null,
            ];
        }

        if ($node === null) {
            return [
                'probe_url' => '',
                'state' => 'unknown',
                'fail_count' => 0,
                'failure_threshold' => $this->nodeReasonService->probeFailureThreshold(),
                'last_checked_at' => null,
                'last_ok_at' => null,
                'last_error' => null,
            ];
        }

        return $this->nodeReasonService->connectivityFromNode($node);
    }
}
