<?php

namespace App\Services;

use App\Models\MonitoredNode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TimeoutCheckerService
{
    public function __construct(
        private readonly StateTransitionService $stateTransitionService,
        private readonly IncidentService $incidentService,
        private readonly AlertService $alertService,
    ) {}

    public function checkTimeouts(): array
    {
        $now = CarbonImmutable::now('UTC');

        $candidates = MonitoredNode::query()
            ->whereIn('current_status', ['ok', 'degraded'])
            ->whereNotNull('last_heartbeat_at')
            ->get();

        $result = [
            'checked' => $candidates->count(),
            'marked_down' => 0,
        ];

        foreach ($candidates as $candidate) {
            $elapsedSeconds = (int) abs($now->diffInSeconds($candidate->last_heartbeat_at, false));
            $thresholdSeconds = (int) ($candidate->timeout_threshold_seconds ?: 180);

            if ($elapsedSeconds <= $thresholdSeconds) {
                continue;
            }

            $incidentToNotify = DB::transaction(function () use ($candidate, $now, $elapsedSeconds, $thresholdSeconds) {
                $node = MonitoredNode::query()
                    ->where('id', $candidate->id)
                    ->lockForUpdate()
                    ->first();

                if ($node === null || $node->current_status === 'down') {
                    return null;
                }

                $transition = $this->stateTransitionService->resolveTimeoutTransition($node->current_status);
                if ($transition === null) {
                    return null;
                }

                $previousStatus = $node->current_status;
                $serverName = $this->resolveServerName($node);
                $undetectedMessage = $this->buildUndetectedMessage($serverName);

                $node->fill([
                    'current_status' => 'down',
                    'last_alert_at' => $now,
                ])->save();

                $incident = $this->incidentService->createIncident([
                    'node_id' => $node->node_id,
                    'from_status' => $previousStatus,
                    'to_status' => $transition['to_status'],
                    'event_type' => $transition['event_type'],
                    'message' => $undetectedMessage,
                    'metadata_json' => [
                        'source' => 'timeout_checker',
                        'elapsed_seconds' => $elapsedSeconds,
                        'threshold_seconds' => $thresholdSeconds,
                        'server_name' => $serverName,
                    ],
                    'occurred_at' => $now,
                ]);

                return [
                    'id' => $incident->id,
                    'node_id' => $node->node_id,
                    'from_status' => $previousStatus,
                    'to_status' => $transition['to_status'],
                    'event_type' => $transition['event_type'],
                    'message' => $undetectedMessage,
                    'server_name' => $serverName,
                ];
            });

            if ($incidentToNotify === null) {
                continue;
            }

            $result['marked_down']++;

            try {
                $this->alertService->sendAlert($incidentToNotify);
            } catch (\Throwable $exception) {
                Log::error('Discord alert send failed during timeout check', [
                    'node_id' => $incidentToNotify['node_id'],
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $result;
    }

    private function resolveServerName(MonitoredNode $node): string
    {
        $payload = is_array($node->last_payload_json) ? $node->last_payload_json : [];

        $candidates = [
            data_get($payload, 'server_name'),
            data_get($payload, 'node_name'),
            data_get($payload, 'host.hostname'),
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
}
