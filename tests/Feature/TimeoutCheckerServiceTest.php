<?php

namespace Tests\Feature;

use App\Models\IncidentEvent;
use App\Models\MonitoredNode;
use App\Services\TimeoutCheckerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TimeoutCheckerServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_timeout_checker_creates_dynamic_undetected_message(): void
    {
        config()->set('monitoring.internal_probe_url_map', []);

        MonitoredNode::query()->create([
            'node_id' => 'node-timeout-1',
            'name' => 'Server Internal A',
            'current_status' => 'ok',
            'heartbeat_status' => 'ok',
            'last_heartbeat_at' => now('UTC')->subMinutes(10),
            'timeout_threshold_seconds' => 180,
        ]);

        $result = app(TimeoutCheckerService::class)->checkTimeouts();

        $this->assertSame(1, (int) ($result['marked_down'] ?? 0));

        $incident = IncidentEvent::query()
            ->where('node_id', 'node-timeout-1')
            ->latest('id')
            ->first();

        $this->assertNotNull($incident);
        $this->assertSame('timeout_detected', $incident->event_type);
        $this->assertSame(
            'Heartbeat Server Internal A tidak diterima dalam batas waktu. Server dinyatakan down.',
            $incident->message
        );
    }

    public function test_timeout_checker_creates_isp_unreachable_only_once_for_continuous_failure(): void
    {
        config()->set('monitoring.internal_probe_url_map', [
            'node-isp-1' => 'https://internal.example/health',
        ]);
        config()->set('monitoring.internal_probe_failure_threshold', 2);

        MonitoredNode::query()->create([
            'node_id' => 'node-isp-1',
            'name' => 'Node ISP 1',
            'current_status' => 'ok',
            'heartbeat_status' => 'ok',
            'last_heartbeat_at' => now('UTC'),
            'timeout_threshold_seconds' => 180,
        ]);

        Http::fake([
            'https://internal.example/health' => Http::sequence()
                ->pushStatus(503)
                ->pushStatus(503)
                ->pushStatus(503),
        ]);

        $service = app(TimeoutCheckerService::class);
        $service->checkTimeouts();
        $service->checkTimeouts();
        $service->checkTimeouts();

        $incidents = IncidentEvent::query()
            ->where('node_id', 'node-isp-1')
            ->where('event_type', 'isp_unreachable')
            ->get();

        $this->assertCount(1, $incidents);
    }

    public function test_timeout_checker_creates_isp_recovered_after_reachability_returns(): void
    {
        config()->set('monitoring.internal_probe_url_map', [
            'node-isp-2' => 'https://internal.example/recovery',
        ]);
        config()->set('monitoring.internal_probe_failure_threshold', 2);

        MonitoredNode::query()->create([
            'node_id' => 'node-isp-2',
            'name' => 'Node ISP 2',
            'current_status' => 'ok',
            'heartbeat_status' => 'ok',
            'last_heartbeat_at' => now('UTC'),
            'timeout_threshold_seconds' => 180,
        ]);

        Http::fake([
            'https://internal.example/recovery' => Http::sequence()
                ->pushStatus(503)
                ->pushStatus(503)
                ->pushStatus(200),
        ]);

        $service = app(TimeoutCheckerService::class);
        $service->checkTimeouts();
        $service->checkTimeouts();
        $service->checkTimeouts();

        $this->assertDatabaseHas('incident_events', [
            'node_id' => 'node-isp-2',
            'event_type' => 'isp_unreachable',
        ]);

        $this->assertDatabaseHas('incident_events', [
            'node_id' => 'node-isp-2',
            'event_type' => 'isp_recovered',
        ]);
    }
}
