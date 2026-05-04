<?php

namespace App\Services;

use App\Models\HeartbeatEvent;
use App\Models\MonitoredNode;
use App\Support\DateFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HeartbeatService
{
    public function __construct(
        private readonly StateTransitionService $stateTransitionService,
        private readonly IncidentService $incidentService,
        private readonly AlertService $alertService,
    ) {}

    public function processHeartbeat(array $payload, CarbonImmutable $receivedAt, ?string $ipAddress, ?string $userAgent): array
    {
        $incidentToNotify = DB::transaction(function () use ($payload, $receivedAt, $ipAddress, $userAgent) {
            $node = MonitoredNode::query()
                ->where('node_id', $payload['node_id'])
                ->lockForUpdate()
                ->first();

            if ($node === null) {
                $node = MonitoredNode::create([
                    'node_id' => $payload['node_id'],
                    'name' => $payload['node_id'],
                    'current_status' => 'unknown',
                    'secret_reference' => 'env:HEARTBEAT_HMAC_SECRET',
                ]);
            }

            $previousStatus = $node->current_status;
            $nextStatus = $payload['overall_status'];
            $summary = $this->buildSummary($payload);

            HeartbeatEvent::create([
                'node_id' => $payload['node_id'],
                'received_at' => $receivedAt,
                'node_timestamp' => CarbonImmutable::parse($payload['timestamp'])->utc(),
                'status' => $nextStatus,
                'payload_json' => $payload,
                'signature_valid' => true,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
            ]);

            $node->fill([
                'current_status' => $nextStatus,
                'last_heartbeat_at' => $receivedAt,
                'last_payload_json' => $payload,
                'last_summary_json' => $summary,
                'secret_reference' => $node->secret_reference ?: 'env:HEARTBEAT_HMAC_SECRET',
            ])->save();

            $transition = $this->stateTransitionService->resolveHeartbeatTransition($previousStatus, $nextStatus);
            if ($transition === null) {
                return null;
            }

            $node->last_alert_at = $receivedAt;
            $node->save();

            $incident = $this->incidentService->createIncident([
                'node_id' => $payload['node_id'],
                'from_status' => $previousStatus,
                'to_status' => $nextStatus,
                'event_type' => $transition['event_type'],
                'message' => $transition['message'],
                'metadata_json' => [
                    'source' => 'heartbeat',
                    'node_timestamp' => $payload['timestamp'],
                ],
                'occurred_at' => $receivedAt,
            ]);

            return [
                'id' => $incident->id,
                'node_id' => $payload['node_id'],
                'from_status' => $previousStatus,
                'to_status' => $nextStatus,
                'event_type' => $transition['event_type'],
                'message' => $transition['message'],
            ];
        });

        if ($incidentToNotify !== null) {
            try {
                $this->alertService->sendAlert($incidentToNotify);
            } catch (\Throwable $exception) {
                Log::error('Discord alert send failed during heartbeat processing', [
                    'node_id' => $incidentToNotify['node_id'],
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return [
            'node_id' => $payload['node_id'],
            'status' => $payload['overall_status'],
            'received_at' => DateFormatter::isoUtc($receivedAt),
        ];
    }

    public function persistInvalidHeartbeat(
        ?string $nodeId,
        ?array $payload,
        ?CarbonImmutable $nodeTimestamp,
        ?string $ipAddress,
        ?string $userAgent,
        string $reason
    ): void {
        $now = CarbonImmutable::now('UTC');

        HeartbeatEvent::create([
            'node_id' => $nodeId ?: 'unknown',
            'received_at' => $now,
            'node_timestamp' => $nodeTimestamp ?: $now,
            'status' => 'unknown',
            'payload_json' => [
                'reason' => $reason,
                'payload' => $payload,
            ],
            'signature_valid' => false,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);
    }

    public function buildSummary(array $payload): array
    {
        return [
            'host' => $payload['host'] ?? [],
            'services' => $this->buildServicesSummary($payload['services'] ?? []),
            'problems' => $payload['problems'] ?? [],
        ];
    }

    public function buildServicesSummary(array $services): array
    {
        $byStatus = [];

        foreach ($services as $service) {
            $status = $service['status'] ?? 'unknown';
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
        }

        return [
            'total' => count($services),
            'by_status' => $byStatus,
            'list' => $services,
        ];
    }
}
