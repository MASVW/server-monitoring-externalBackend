<?php

namespace App\Services;

use App\Models\MonitoredNode;
use App\Support\DateFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TimeoutCheckerService
{
    public function __construct(
        private readonly StateTransitionService $stateTransitionService,
        private readonly IncidentService $incidentService,
        private readonly NodeReasonService $nodeReasonService,
        private readonly NodeHealthDecisionService $nodeHealthDecisionService,
        private readonly InternalProbeService $internalProbeService,
        private readonly AlertService $alertService,
    ) {}

    public function checkTimeouts(): array
    {
        $now = CarbonImmutable::now('UTC');
        $candidates = MonitoredNode::query()->get();

        $result = [
            'checked' => $candidates->count(),
            'probe_checked' => 0,
            'marked_down' => 0,
            'isp_degraded' => 0,
            'isp_recovered' => 0,
        ];

        foreach ($candidates as $candidate) {
            $probeUrl = $this->nodeReasonService->resolveProbeUrl((string) $candidate->node_id);
            $probeAttempted = $probeUrl !== null;
            $probeResult = null;

            if ($probeAttempted) {
                $result['probe_checked']++;
                $probeResult = $this->internalProbeService->probe($probeUrl);
            }

            $incidentToNotify = DB::transaction(function () use ($candidate, $now, $probeResult, $probeAttempted) {
                $node = MonitoredNode::query()
                    ->where('id', $candidate->id)
                    ->lockForUpdate()
                    ->first();

                if ($node === null) {
                    return null;
                }

                $previousStatus = $this->nodeReasonService->normalizeNodeStatus((string) $node->current_status);
                $previousReasonCode = $this->nodeReasonService->normalizeReasonCode((string) ($node->reason_code ?? 'unknown'));

                $decision = $this->nodeHealthDecisionService->evaluate(
                    node: $node,
                    now: $now,
                    probeResult: $probeResult,
                    probeAttempted: $probeAttempted,
                    strictProbeReachability: true
                );

                $node->fill([
                    'current_status' => $decision['status'],
                    'reason_code' => $decision['reason_code'],
                    'probe_state' => $decision['probe']['state'],
                    'probe_fail_count' => $decision['probe']['fail_count'],
                    'last_probe_checked_at' => $decision['probe']['last_checked_at'],
                    'last_probe_ok_at' => $decision['probe']['last_ok_at'],
                    'last_probe_error' => $decision['probe']['last_error'],
                ])->save();

                $incidentTransition = $this->resolveIncidentTransition(
                    node: $node,
                    previousStatus: $previousStatus,
                    previousReasonCode: $previousReasonCode,
                    decision: $decision
                );

                if ($incidentTransition === null) {
                    return null;
                }

                $node->last_alert_at = $now;
                $node->save();

                $metadata = [
                    'source' => 'timeout_checker',
                    'reason_code' => $decision['reason_code'],
                    'previous_reason_code' => $previousReasonCode,
                    'heartbeat_status' => $decision['heartbeat_status'],
                    'heartbeat_fresh' => $decision['heartbeat_fresh'],
                    'connectivity' => [
                        'probe_url' => $decision['probe']['url'],
                        'state' => $decision['probe']['state'],
                        'fail_count' => $decision['probe']['fail_count'],
                        'failure_threshold' => $decision['probe']['threshold'],
                        'last_checked_at' => DateFormatter::isoUtc($decision['probe']['last_checked_at']),
                        'last_ok_at' => DateFormatter::isoUtc($decision['probe']['last_ok_at']),
                        'last_error' => $decision['probe']['last_error'],
                    ],
                ];

                if (is_array($probeResult)) {
                    $metadata['probe_result'] = $probeResult;
                }

                $incident = $this->incidentService->createIncident([
                    'node_id' => $node->node_id,
                    'from_status' => $previousStatus,
                    'to_status' => $decision['status'],
                    'event_type' => $incidentTransition['event_type'],
                    'message' => $incidentTransition['message'],
                    'metadata_json' => $metadata,
                    'occurred_at' => $now,
                ]);

                return [
                    'id' => $incident->id,
                    'node_id' => $node->node_id,
                    'from_status' => $previousStatus,
                    'to_status' => $decision['status'],
                    'event_type' => $incidentTransition['event_type'],
                    'reason_code' => $decision['reason_code'],
                    'message' => $incidentTransition['message'],
                    'connectivity' => $metadata['connectivity'],
                ];
            });

            if ($incidentToNotify === null) {
                continue;
            }

            if (
                ($incidentToNotify['to_status'] ?? '') === 'down'
                && ($incidentToNotify['from_status'] ?? '') !== 'down'
            ) {
                $result['marked_down']++;
            }

            if (($incidentToNotify['event_type'] ?? '') === 'isp_unreachable') {
                $result['isp_degraded']++;
            }

            if (($incidentToNotify['event_type'] ?? '') === 'isp_recovered') {
                $result['isp_recovered']++;
            }

            try {
                $this->alertService->sendAlert($incidentToNotify);
            } catch (\Throwable $exception) {
                Log::error('Alert send failed during monitoring check', [
                    'node_id' => $incidentToNotify['node_id'],
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $result;
    }

    private function resolveIncidentTransition(
        MonitoredNode $node,
        string $previousStatus,
        string $previousReasonCode,
        array $decision
    ): ?array {
        $nextStatus = $this->nodeReasonService->normalizeNodeStatus((string) ($decision['status'] ?? 'unknown'));
        $nextReasonCode = $this->nodeReasonService->normalizeReasonCode((string) ($decision['reason_code'] ?? 'unknown'));

        if ($previousReasonCode !== 'isp_down' && $nextReasonCode === 'isp_down') {
            return [
                'event_type' => 'isp_unreachable',
                'message' => (string) ($decision['message'] ?? ''),
            ];
        }

        if (
            $previousReasonCode === 'isp_down'
            && $nextReasonCode !== 'isp_down'
            && strtolower(trim((string) data_get($decision, 'probe.state'))) === 'reachable'
        ) {
            $serverName = $this->nodeReasonService->resolveServerName($node);

            return [
                'event_type' => 'isp_recovered',
                'message' => $this->nodeReasonService->buildIspRecoveredMessage($serverName),
            ];
        }

        if ($previousStatus === $nextStatus) {
            return null;
        }

        if ($nextReasonCode === 'heartbeat_timeout') {
            return [
                'event_type' => 'timeout_detected',
                'message' => (string) ($decision['message'] ?? ''),
            ];
        }

        $transition = $this->stateTransitionService->resolveHeartbeatTransition($previousStatus, $nextStatus);
        if ($transition === null) {
            return null;
        }

        return [
            'event_type' => $transition['event_type'],
            'message' => (string) ($decision['message'] ?? $transition['message']),
        ];
    }
}
