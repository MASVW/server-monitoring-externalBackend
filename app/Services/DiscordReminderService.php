<?php

namespace App\Services;

use App\Models\MonitoredNode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

class DiscordReminderService
{
    public function __construct(
        private readonly AlertService $alertService,
    ) {}

    public function sendReminders(): array
    {
        if (! $this->alertService->isDiscordConfigured()) {
            return [
                'checked' => 0,
                'sent' => 0,
                'skipped' => 'discord_not_configured',
            ];
        }

        $now = CarbonImmutable::now('UTC');
        $nodes = MonitoredNode::all();

        $sentCount = 0;

        foreach ($nodes as $node) {
            if (! $this->shouldAlertNode($node)) {
                continue;
            }

            if (! $this->shouldSendReminder($node, $now)) {
                continue;
            }

            try {
                $result = $this->alertService->sendReminderAlert($node);

                if (($result['sent'] ?? false) === true) {
                    $sentCount++;
                    $node->update(['last_alert_at' => $now]);
                }
            } catch (\Throwable $exception) {
                Log::error('Discord reminder send failed', [
                    'node_id' => $node->node_id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return [
            'checked' => $nodes->count(),
            'sent' => $sentCount,
        ];
    }

    private function shouldSendReminder(MonitoredNode $node, CarbonImmutable $now): bool
    {
        if ($node->last_alert_at === null) {
            return true;
        }

        $elapsedSeconds = (int) abs($now->diffInSeconds($node->last_alert_at, false));
        return $elapsedSeconds >= (int) config('discord.alert_interval_seconds');
    }

    private function shouldAlertNode(MonitoredNode $node): bool
    {
        if (in_array($node->current_status, ['down', 'degraded'], true)) {
            return true;
        }

        $byStatus = $node->last_summary_json['services']['by_status'] ?? [];

        foreach ($byStatus as $status => $count) {
            if (strtolower((string) $status) === 'online') {
                continue;
            }

            if ((int) $count > 0) {
                return true;
            }
        }

        return false;
    }
}
