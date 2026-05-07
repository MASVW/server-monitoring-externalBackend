<?php

namespace App\Services;

use App\Models\MonitoredNode;
use App\Models\MonitoringAlertState;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppAlertService
{
    public function isConfigured(): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        if ($this->provider() !== 'fonnte') {
            return false;
        }

        return trim((string) config('whatsapp.fonnte.token')) !== ''
            && trim((string) config('whatsapp.fonnte.target_group_id')) !== ''
            && trim((string) config('whatsapp.fonnte.api_url')) !== '';
    }

    public function sendIncidentAlert(array $context): array
    {
        if (! $this->isEnabled()) {
            return ['sent' => false, 'reason' => 'whatsapp_disabled'];
        }

        if (! $this->isConfigured()) {
            return ['sent' => false, 'reason' => 'whatsapp_not_configured'];
        }

        $prepared = $this->prepareContext($context);
        if (! $prepared['is_unhealthy']) {
            return ['sent' => false, 'reason' => 'status_not_alertable'];
        }

        $now = CarbonImmutable::now('UTC');
        $state = $this->getStateForUpdate($prepared['node_id']);

        if ($state !== null && $this->isActiveIncident($state)) {
            return ['sent' => false, 'reason' => 'incident_already_active'];
        }

        $prepared['incident_started_at'] = $now;
        $message = $this->buildInitialAlertMessage($prepared);

        $sendResult = $this->sendFonnteMessage($message);

        DB::transaction(function () use ($prepared, $now, $state): void {
            $record = $state ?? new MonitoringAlertState([
                'node_id' => $prepared['node_id'],
                'channel' => 'whatsapp',
            ]);

            $record->fill([
                'current_status' => $prepared['status'],
                'incident_key' => $this->incidentKey($prepared['node_id']),
                'incident_started_at' => $now,
                'last_alert_at' => $now,
                'last_reminder_at' => null,
                'recovered_at' => null,
                'recovery_notified_at' => null,
                'last_payload_json' => $this->sanitizePayloadForState($prepared),
            ])->save();
        });

        return [
            'sent' => true,
            'channel' => 'whatsapp',
            'type' => 'incident',
            'provider_response' => $sendResult,
        ];
    }

    public function sendReminderAlert(array $context): array
    {
        if (! $this->isEnabled()) {
            return ['sent' => false, 'reason' => 'whatsapp_disabled'];
        }

        if (! $this->isConfigured()) {
            return ['sent' => false, 'reason' => 'whatsapp_not_configured'];
        }

        $prepared = $this->prepareContext($context);
        if (! $prepared['is_unhealthy']) {
            return ['sent' => false, 'reason' => 'status_not_alertable'];
        }

        $now = CarbonImmutable::now('UTC');
        $state = $this->getStateForUpdate($prepared['node_id']);

        if ($state === null || ! $this->isActiveIncident($state)) {
            return $this->sendIncidentAlert($prepared);
        }

        $intervalMinutes = max(1, (int) config('whatsapp.reminder_interval_minutes', 60));
        $lastReminderAt = $state->last_reminder_at ?? $state->last_alert_at;

        if ($lastReminderAt !== null) {
            $elapsedMinutes = (int) abs($now->diffInMinutes($lastReminderAt, false));
            if ($elapsedMinutes < $intervalMinutes) {
                return ['sent' => false, 'reason' => 'reminder_not_due'];
            }
        }

        $prepared['incident_started_at'] = $state->incident_started_at;
        $prepared['last_alert_at'] = $state->last_alert_at;
        $message = $this->buildReminderMessage($prepared);

        $sendResult = $this->sendFonnteMessage($message);

        DB::transaction(function () use ($state, $prepared, $now): void {
            $locked = $this->getStateForUpdate($prepared['node_id']) ?? $state;
            $locked->fill([
                'current_status' => $prepared['status'],
                'last_alert_at' => $now,
                'last_reminder_at' => $now,
                'last_payload_json' => $this->sanitizePayloadForState($prepared),
            ])->save();
        });

        return [
            'sent' => true,
            'channel' => 'whatsapp',
            'type' => 'reminder',
            'provider_response' => $sendResult,
        ];
    }

    public function sendRecoveryAlert(array $context): array
    {
        if (! $this->isEnabled()) {
            return ['sent' => false, 'reason' => 'whatsapp_disabled'];
        }

        if (! $this->isConfigured()) {
            return ['sent' => false, 'reason' => 'whatsapp_not_configured'];
        }

        $prepared = $this->prepareContext($context);

        $now = CarbonImmutable::now('UTC');
        $state = $this->getStateForUpdate($prepared['node_id']);

        if ($state !== null && $state->recovery_notified_at !== null) {
            return ['sent' => false, 'reason' => 'recovery_already_notified'];
        }

        if ($state === null || ! $this->isActiveIncident($state)) {
            if (! in_array((string) ($prepared['previous_status'] ?? 'unknown'), ['down', 'degraded'], true)) {
                return ['sent' => false, 'reason' => 'no_active_incident'];
            }

            $prepared['status'] = 'ok';
            $prepared['incident_started_at'] = $prepared['incident_started_at'] ?? null;
            $prepared['recovery_time'] = $now;

            $message = $this->buildRecoveryMessage($prepared);
            $sendResult = $this->sendFonnteMessage($message);

            DB::transaction(function () use ($prepared, $now): void {
                $record = MonitoringAlertState::query()->firstOrNew([
                    'node_id' => $prepared['node_id'],
                    'channel' => 'whatsapp',
                ]);

                $record->fill([
                    'current_status' => 'ok',
                    'incident_key' => $this->incidentKey($prepared['node_id']),
                    'incident_started_at' => $record->incident_started_at,
                    'last_alert_at' => $now,
                    'recovered_at' => $now,
                    'recovery_notified_at' => $now,
                    'last_payload_json' => $this->sanitizePayloadForState($prepared),
                ])->save();
            });

            return [
                'sent' => true,
                'channel' => 'whatsapp',
                'type' => 'recovery',
                'provider_response' => $sendResult,
            ];
        }

        $prepared['status'] = 'ok';
        $prepared['previous_status'] = $this->normalizeServiceBucket((string) $state->current_status);
        $prepared['incident_started_at'] = $state->incident_started_at;
        $prepared['recovery_time'] = $now;

        $message = $this->buildRecoveryMessage($prepared);

        $sendResult = $this->sendFonnteMessage($message);

        DB::transaction(function () use ($state, $prepared, $now): void {
            $locked = $this->getStateForUpdate($prepared['node_id']) ?? $state;
            $locked->fill([
                'current_status' => 'ok',
                'recovered_at' => $now,
                'recovery_notified_at' => $now,
                'last_alert_at' => $now,
                'last_payload_json' => $this->sanitizePayloadForState($prepared),
            ])->save();
        });

        return [
            'sent' => true,
            'channel' => 'whatsapp',
            'type' => 'recovery',
            'provider_response' => $sendResult,
        ];
    }

    public function sendTestMessage(): array
    {
        if (! $this->isEnabled()) {
            return ['sent' => false, 'reason' => 'whatsapp_disabled'];
        }

        if (! $this->isConfigured()) {
            return ['sent' => false, 'reason' => 'whatsapp_not_configured'];
        }

        $result = $this->sendFonnteMessage($this->buildTestMessage());

        return [
            'sent' => true,
            'channel' => 'whatsapp',
            'type' => 'test',
            'provider_response' => $result,
        ];
    }

    public function sendScheduledReminders(): array
    {
        if (! $this->isEnabled()) {
            return ['checked' => 0, 'sent' => 0, 'skipped' => 'whatsapp_disabled'];
        }

        if (! $this->isConfigured()) {
            return ['checked' => 0, 'sent' => 0, 'skipped' => 'whatsapp_not_configured'];
        }

        $nodes = MonitoredNode::all();
        $sent = 0;

        foreach ($nodes as $node) {
            $context = $this->buildContextFromNode($node);

            try {
                $status = $context['status'];
                $isUnhealthy = (bool) ($context['is_unhealthy'] ?? false);

                if ($isUnhealthy) {
                    $state = MonitoringAlertState::query()
                        ->where('node_id', $node->node_id)
                        ->where('channel', 'whatsapp')
                        ->first();

                    if ($state === null || ! $this->isActiveIncident($state)) {
                        $result = $this->sendIncidentAlert($context);
                    } else {
                        $result = $this->sendReminderAlert($context);
                    }
                } elseif ($status === 'ok') {
                    $result = $this->sendRecoveryAlert($context);
                } else {
                    $result = ['sent' => false, 'reason' => 'status_not_alertable'];
                }

                if (($result['sent'] ?? false) === true) {
                    $sent++;
                }
            } catch (\Throwable $exception) {
                Log::error('WhatsApp reminder cycle failed', [
                    'node_id' => $node->node_id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return [
            'checked' => $nodes->count(),
            'sent' => $sent,
        ];
    }

    private function buildInitialAlertMessage(array $context): string
    {
        $status = strtoupper((string) $context['status']);
        $incidentMessage = $this->resolveIncidentMessage($context);

        $sections = [
            '🚨 *SERVER MONITORING ALERT — '.$status.'*',
            '',
            '⚠️ *Keterangan*',
            $incidentMessage,
            '',
            $this->buildNodeSection($context),
            '',
            $this->buildConditionSection($context),
            '',
            $this->buildProblemServicesSection($context),
            '',
            $this->buildNormalServicesSection($context),
            '',
            $this->buildResourceSection($context),
            '',
            $this->buildProblemsSection($context),
            '',
            '🕒 *Alert Time*',
            $this->formatHumanWibDateTime(now('UTC')),
        ];

        return $this->clipMessage(implode("\n", $sections));
    }

    private function buildReminderMessage(array $context): string
    {
        $status = strtoupper((string) $context['status']);
        $incidentStartedAt = $context['incident_started_at'] ?? null;
        $incidentMessage = $this->resolveIncidentMessage($context);

        $duration = '-';
        if ($incidentStartedAt !== null) {
            try {
                $duration = $this->formatDuration((int) Carbon::parse($incidentStartedAt)->diffInSeconds(now('UTC')));
            } catch (\Throwable) {
                $duration = '-';
            }
        }

        $serviceSummary = $context['service_summary'] ?? $this->summarizeServices($context['summary'] ?? []);
        $counts = $serviceSummary['counts'] ?? [];

        $sections = [
            '🔁 *SERVER MONITORING REMINDER — MASIH '.$status.'*',
            '',
            'Node *'.($context['node_id'] ?? 'unknown').'* masih berada dalam kondisi tidak normal.',
            '',
            '⚠️ *Keterangan*',
            $incidentMessage,
            '',
            '🖥️ *Node*',
            '• ID: '.($context['node_id'] ?? 'unknown'),
            '• Status: '.$this->statusEmoji((string) $context['status']).' *'.$status.'*',
            '• Incident Started: '.$this->formatHumanWibDateTime($incidentStartedAt),
            '• Last Heartbeat: '.$this->formatHumanWibDateTime($context['last_heartbeat_at'] ?? null),
            '• Durasi Incident: '.$duration,
            '• Reminder Interval: '.max(1, (int) config('whatsapp.reminder_interval_minutes', 60)).' menit',
            '',
            '📌 *Ringkasan Kondisi*',
            '• Total Service: '.(int) ($serviceSummary['total'] ?? 0),
            '• Online: 🟢 '.(int) ($counts['online'] ?? 0),
            '• Down: 🔴 '.(int) ($counts['down'] ?? 0),
            '• Potential Unhealthy Services: '.(int) ($serviceSummary['potential_unhealthy'] ?? 0),
            '',
            '🔴 *Masih Bermasalah*',
            $this->problemServiceLines($serviceSummary),
            '',
            $this->buildResourceSection($context),
            '',
            '🕒 *Reminder Time*',
            $this->formatHumanWibDateTime(now('UTC')),
        ];

        return $this->clipMessage(implode("\n", $sections));
    }

    private function buildRecoveryMessage(array $context): string
    {
        $serviceSummary = $context['service_summary'] ?? $this->summarizeServices($context['summary'] ?? []);
        $counts = $serviceSummary['counts'] ?? [];

        $incidentStartedAt = $context['incident_started_at'] ?? null;
        $recoveryTime = $context['recovery_time'] ?? now('UTC');

        $duration = '-';
        if ($incidentStartedAt !== null) {
            try {
                $duration = $this->formatDuration((int) Carbon::parse($incidentStartedAt)->diffInSeconds(Carbon::parse($recoveryTime)));
            } catch (\Throwable) {
                $duration = '-';
            }
        }

        $sections = [
            '✅ *SERVER RECOVERY — NODE NORMAL*',
            '',
            'Node *'.($context['node_id'] ?? 'unknown').'* sudah kembali normal.',
            '',
            '🖥️ *Node*',
            '• ID: '.($context['node_id'] ?? 'unknown'),
            '• Status Sebelumnya: '.$this->statusEmoji((string) ($context['previous_status'] ?? 'unknown')).' '.strtoupper((string) ($context['previous_status'] ?? 'unknown')),
            '• Status Sekarang: 🟢 *OK*',
            '• Incident Started: '.$this->formatHumanWibDateTime($incidentStartedAt),
            '• Recovery Time: '.$this->formatHumanWibDateTime($recoveryTime),
            '• Total Durasi Gangguan: '.$duration,
            '',
            '📌 *Recovery Summary*',
            '• Semua service utama sudah kembali normal',
            '• Potential Unhealthy Services: '.(int) ($serviceSummary['potential_unhealthy'] ?? 0),
            '• Current Online Services: '.(int) ($counts['online'] ?? 0).' / '.(int) ($serviceSummary['total'] ?? 0),
            '',
            '📊 *Resource Server Saat Recovery*',
            $this->resourceLines($context),
            '',
            '🕒 *Notification Time*',
            $this->formatHumanWibDateTime(now('UTC')),
        ];

        return $this->clipMessage(implode("\n", $sections));
    }

    private function buildTestMessage(): string
    {
        $lines = [
            '✅ *SERVER MONITORING WHATSAPP TEST*',
            '',
            'WhatsApp alert delivery is working.',
            '',
            '🕒 *Test Time*',
            $this->formatHumanWibDateTime(now('UTC')),
        ];

        return implode("\n", $lines);
    }

    private function sendFonnteMessage(string $message): array
    {
        if ($this->provider() !== 'fonnte') {
            throw new \RuntimeException('WhatsApp provider is not supported');
        }

        $url = (string) config('whatsapp.fonnte.api_url');
        $token = (string) config('whatsapp.fonnte.token');
        $target = (string) config('whatsapp.fonnte.target_group_id');

        $response = Http::withHeaders([
            'Authorization' => $token,
        ])
            ->asForm()
            ->post($url, [
                'target' => $target,
                'message' => $this->clipMessage($message),
            ]);

        if (! $response->successful()) {
            $body = $this->clipMessage((string) $response->body(), 300);
            throw new \RuntimeException("Fonnte API error {$response->status()}: {$body}");
        }

        $json = $response->json();
        if (is_array($json) && array_key_exists('status', $json) && $json['status'] === false) {
            $reason = $this->clipMessage((string) ($json['reason'] ?? 'Unknown Fonnte error'), 300);
            throw new \RuntimeException('Fonnte rejected request: '.$reason);
        }

        return [
            'status_code' => $response->status(),
            'body' => $this->clipMessage((string) $response->body(), 300),
        ];
    }

    private function buildNodeSection(array $context): string
    {
        $host = is_array(data_get($context, 'summary.host')) ? data_get($context, 'summary.host') : [];
        $status = (string) ($context['status'] ?? 'unknown');

        $uptime = $this->firstNumericFromPaths($host, ['uptime', 'uptimeSeconds', 'uptime_seconds']);

        $lines = [
            '🖥️ *Node*',
            '• ID: '.($context['node_id'] ?? 'unknown'),
            '• Hostname: '.(trim((string) ($host['hostname'] ?? '-')) ?: '-'),
            '• Platform: '.(trim((string) ($host['platform'] ?? '-')) ?: '-'),
            '• Status: '.$this->statusEmoji($status).' *'.strtoupper($status).'*',
            '• Last Heartbeat: '.$this->formatHumanWibDateTime($context['last_heartbeat_at'] ?? null),
            '• Timeout Threshold: '.(int) ($context['timeout_threshold_seconds'] ?? 180).' detik',
            '• Uptime: '.($uptime !== null ? $this->formatDuration((int) round($uptime)) : '-'),
        ];

        return implode("\n", $lines);
    }

    private function buildConditionSection(array $context): string
    {
        $serviceSummary = $context['service_summary'] ?? $this->summarizeServices($context['summary'] ?? []);
        $counts = $serviceSummary['counts'] ?? [
            'online' => 0,
            'degraded' => 0,
            'down' => 0,
            'unknown' => 0,
        ];

        $lines = [
            '📌 *Ringkasan Kondisi*',
            '• Total Service: '.(int) ($serviceSummary['total'] ?? 0),
            '• Online: 🟢 '.(int) ($counts['online'] ?? 0),
            '• Degraded: 🟡 '.(int) ($counts['degraded'] ?? 0),
            '• Down: 🔴 '.(int) ($counts['down'] ?? 0),
            '• Unknown: ⚪ '.(int) ($counts['unknown'] ?? 0),
            '• Potential Unhealthy Services: '.(int) ($serviceSummary['potential_unhealthy'] ?? 0),
        ];

        return implode("\n", $lines);
    }

    private function buildProblemServicesSection(array $context): string
    {
        $serviceSummary = $context['service_summary'] ?? $this->summarizeServices($context['summary'] ?? []);

        return "🔴 *Layanan Bermasalah*\n".$this->problemServiceLines($serviceSummary);
    }

    private function problemServiceLines(array $serviceSummary): string
    {
        $problemServices = $serviceSummary['problem_services'] ?? [];

        if (! is_array($problemServices) || $problemServices === []) {
            if ((int) ($serviceSummary['potential_unhealthy'] ?? 0) > 0) {
                return 'Ada layanan tidak sehat, tetapi detail nama service tidak tersedia.';
            }

            return 'Tidak ada layanan bermasalah.';
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

        return $lines === [] ? 'Tidak ada layanan bermasalah.' : implode("\n", $lines);
    }

    private function buildNormalServicesSection(array $context): string
    {
        $serviceSummary = $context['service_summary'] ?? $this->summarizeServices($context['summary'] ?? []);
        $online = $serviceSummary['grouped_names']['online'] ?? [];

        if (! is_array($online) || $online === []) {
            return "🟢 *Layanan Normal*\nTidak ada layanan online.";
        }

        $max = 8;
        $shown = array_slice($online, 0, $max);
        $remaining = count($online) - count($shown);

        $value = implode(', ', $shown);
        if ($remaining > 0) {
            $value .= "\n+{$remaining} layanan lainnya masih online";
        }

        return "🟢 *Layanan Normal*\n".$value;
    }

    private function buildResourceSection(array $context): string
    {
        return "📊 *Resource Server*\n".$this->resourceLines($context);
    }

    private function resourceLines(array $context): string
    {
        $host = is_array(data_get($context, 'summary.host')) ? data_get($context, 'summary.host') : [];

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

        return implode("\n", $lines);
    }

    private function buildProblemsSection(array $context): string
    {
        $summary = is_array($context['summary'] ?? null) ? $context['summary'] : [];
        $problems = $summary['problems'] ?? [];

        if (! is_array($problems) || count($problems) === 0) {
            return "✅ *Problems*\nTidak ada problem detail dari agent.";
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

        return "✅ *Problems*\n".implode("\n", $lines);
    }

    private function clipMessage(string $message, int $max = 3500): string
    {
        if ($max <= 0) {
            return '';
        }

        if (mb_strlen($message) <= $max) {
            return $message;
        }

        if ($max <= 3) {
            return mb_substr($message, 0, $max);
        }

        return rtrim(mb_substr($message, 0, $max - 3)).'...';
    }

    private function formatHumanWibDateTime(mixed $value): string
    {
        if (! $value) {
            return 'never';
        }

        try {
            return Carbon::parse($value)
                ->timezone((string) config('whatsapp.timezone', 'Asia/Jakarta'))
                ->locale('id')
                ->translatedFormat('d F Y, h:i A').' WIB';
        } catch (\Throwable) {
            return '-';
        }
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

    private function statusEmoji(string $status): string
    {
        return match ($this->normalizeServiceBucket($status)) {
            'online' => '🟢',
            'degraded' => '🟡',
            'down' => '🔴',
            default => '⚪',
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

    private function buildContextFromNode(MonitoredNode $node): array
    {
        $summary = is_array($node->last_summary_json) ? $node->last_summary_json : [];
        $serverName = $this->resolveServerNameFromNode($node, $summary);
        $status = $this->normalizeServiceBucket((string) $node->current_status);

        return $this->prepareContext([
            'node_id' => $node->node_id,
            'server_name' => $serverName,
            'status' => $status,
            'summary' => $summary,
            'timeout_threshold_seconds' => $node->timeout_threshold_seconds,
            'last_heartbeat_at' => $node->last_heartbeat_at,
            'message' => in_array($status, ['down', 'unknown'], true)
                ? $this->buildUndetectedMessage($serverName)
                : null,
        ]);
    }

    private function prepareContext(array $context): array
    {
        $nodeId = trim((string) ($context['node_id'] ?? 'unknown'));
        $status = $this->normalizeServiceBucket((string) ($context['status'] ?? $context['to_status'] ?? 'unknown'));
        $previousStatus = $this->normalizeServiceBucket((string) ($context['previous_status'] ?? $context['from_status'] ?? 'unknown'));

        $summary = $context['summary'] ?? null;
        if (! is_array($summary)) {
            $summary = [];
        }

        $serviceSummary = $this->summarizeServices($summary);
        $isUnhealthy = $status === 'down'
            || $status === 'degraded'
            || (int) ($serviceSummary['potential_unhealthy'] ?? 0) > 0;

        return array_merge($context, [
            'node_id' => $nodeId !== '' ? $nodeId : 'unknown',
            'server_name' => trim((string) ($context['server_name'] ?? '')),
            'status' => $status,
            'previous_status' => $previousStatus,
            'summary' => $summary,
            'service_summary' => $serviceSummary,
            'is_unhealthy' => $isUnhealthy,
            'timeout_threshold_seconds' => (int) ($context['timeout_threshold_seconds'] ?? 180),
        ]);
    }

    private function sanitizePayloadForState(array $context): array
    {
        $lastHeartbeatIso = null;
        if (isset($context['last_heartbeat_at']) && $context['last_heartbeat_at'] !== null) {
            try {
                $lastHeartbeatIso = Carbon::parse($context['last_heartbeat_at'])->utc()->toIso8601String();
            } catch (\Throwable) {
                $lastHeartbeatIso = null;
            }
        }

        return [
            'node_id' => $context['node_id'] ?? 'unknown',
            'status' => $context['status'] ?? 'unknown',
            'previous_status' => $context['previous_status'] ?? 'unknown',
            'summary' => is_array($context['summary'] ?? null) ? $context['summary'] : [],
            'timeout_threshold_seconds' => (int) ($context['timeout_threshold_seconds'] ?? 180),
            'last_heartbeat_at' => $lastHeartbeatIso,
        ];
    }

    private function incidentKey(string $nodeId): string
    {
        return 'node:'.$nodeId;
    }

    private function resolveIncidentMessage(array $context): string
    {
        $message = trim((string) ($context['message'] ?? ''));
        if ($message !== '') {
            return $message;
        }

        if (! in_array((string) ($context['status'] ?? 'unknown'), ['down', 'unknown'], true)) {
            return '-';
        }

        $serverName = trim((string) ($context['server_name'] ?? ''));
        if ($serverName === '') {
            $serverName = trim((string) ($context['node_id'] ?? 'unknown'));
        }

        return $this->buildUndetectedMessage($serverName);
    }

    private function resolveServerNameFromNode(MonitoredNode $node, array $summary): string
    {
        $payload = is_array($node->last_payload_json) ? $node->last_payload_json : [];
        $host = is_array(data_get($summary, 'host')) ? data_get($summary, 'host') : [];

        $candidates = [
            data_get($payload, 'server_name'),
            data_get($payload, 'node_name'),
            data_get($payload, 'host.hostname'),
            data_get($host, 'hostname'),
            $node->name,
            $node->node_id,
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return $node->node_id;
    }

    private function buildUndetectedMessage(string $serverName): string
    {
        return sprintf(
            'Tidak ditemukan informasi %s. Kemungkinan ISP down, server internal down, service heartbeat mati, atau external tidak menerima sinyal.',
            $serverName
        );
    }

    private function getStateForUpdate(string $nodeId): ?MonitoringAlertState
    {
        return MonitoringAlertState::query()
            ->where('node_id', $nodeId)
            ->where('channel', 'whatsapp')
            ->first();
    }

    private function isActiveIncident(MonitoringAlertState $state): bool
    {
        if ($state->incident_started_at === null) {
            return false;
        }

        return $state->recovery_notified_at === null;
    }

    private function provider(): string
    {
        return strtolower((string) config('whatsapp.provider', 'fonnte'));
    }

    private function isEnabled(): bool
    {
        return (bool) config('whatsapp.enabled', false);
    }
}
