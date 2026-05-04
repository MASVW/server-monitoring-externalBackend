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

                $node->fill([
                    'current_status' => 'down',
                    'last_alert_at' => $now,
                ])->save();

                $incident = $this->incidentService->createIncident([
                    'node_id' => $node->node_id,
                    'from_status' => $previousStatus,
                    'to_status' => $transition['to_status'],
                    'event_type' => $transition['event_type'],
                    'message' => $transition['message']." ({$elapsedSeconds}s > {$thresholdSeconds}s)",
                    'metadata_json' => [
                        'source' => 'timeout_checker',
                        'elapsed_seconds' => $elapsedSeconds,
                        'threshold_seconds' => $thresholdSeconds,
                    ],
                    'occurred_at' => $now,
                ]);

                return [
                    'id' => $incident->id,
                    'node_id' => $node->node_id,
                    'from_status' => $previousStatus,
                    'to_status' => $transition['to_status'],
                    'event_type' => $transition['event_type'],
                    'message' => $transition['message'],
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
}
