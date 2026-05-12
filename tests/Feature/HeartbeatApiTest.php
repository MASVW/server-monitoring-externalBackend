<?php

namespace Tests\Feature;

use App\Models\HeartbeatEvent;
use App\Models\MonitoredNode;
use App\Services\TimeoutCheckerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SendsHeartbeatRequests;
use Tests\TestCase;

class HeartbeatApiTest extends TestCase
{
    use RefreshDatabase;
    use SendsHeartbeatRequests;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('heartbeat.hmac_secret', 'test-secret');
        config()->set('heartbeat.allowed_drift_seconds', 300);
        config()->set('monitoring.internal_probe_url_map', [
            'node-01' => 'https://internal.example/health',
        ]);
        config()->set('monitoring.internal_probe_failure_threshold', 2);
    }

    public function test_accepts_valid_signed_heartbeat(): void
    {
        $response = $this->sendHeartbeat();

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Heartbeat received')
            ->assertJsonPath('data.node_id', 'node-01')
            ->assertJsonPath('data.status', 'ok');

        $this->assertDatabaseHas('monitored_nodes', [
            'node_id' => 'node-01',
            'current_status' => 'ok',
        ]);

        $this->assertDatabaseHas('heartbeat_events', [
            'node_id' => 'node-01',
            'status' => 'ok',
            'signature_valid' => 1,
        ]);
    }

    public function test_rejects_invalid_signature_and_stores_invalid_event(): void
    {
        $response = $this->sendHeartbeat(signature: 'invalid-signature');

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Invalid heartbeat signature');

        $this->assertDatabaseHas('heartbeat_events', [
            'node_id' => 'node-01',
            'status' => 'unknown',
            'signature_valid' => 0,
        ]);
    }

    public function test_rejects_old_timestamp(): void
    {
        $timestamp = now('UTC')->subMinutes(10)->format('Y-m-d\\TH:i:s.v\\Z');
        $response = $this->sendHeartbeat(headerTimestamp: $timestamp, payloadOverrides: ['timestamp' => $timestamp]);

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Invalid request timestamp');

        $this->assertDatabaseHas('heartbeat_events', [
            'node_id' => 'node-01',
            'signature_valid' => 0,
        ]);
    }

    public function test_status_endpoint_returns_latest_node_state(): void
    {
        $this->sendHeartbeat(payloadOverrides: ['overall_status' => 'degraded'])->assertStatus(200);

        $status = $this->getJson('/api/v1/status/node-01');

        $status->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.node_id', 'node-01')
            ->assertJsonPath('data.server_name', 'node-01')
            ->assertJsonPath('data.status', 'degraded')
            ->assertJsonPath('data.current_status', 'degraded')
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'node_id',
                    'server_name',
                    'status',
                    'current_status',
                    'last_heartbeat_at',
                    'heartbeat_interval_seconds',
                    'timeout_threshold_seconds',
                    'reason_code',
                    'message',
                    'connectivity' => [
                        'probe_url',
                        'state',
                        'fail_count',
                        'failure_threshold',
                        'last_checked_at',
                        'last_ok_at',
                        'last_error',
                    ],
                    'summary',
                ],
            ]);
    }

    public function test_status_endpoint_returns_404_for_unknown_node(): void
    {
        $response = $this->getJson('/api/v1/status/unknown-node');

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Node not found');
    }

    public function test_accepts_internal_backend_heartbeat_contract(): void
    {
        $response = $this->sendInternalHeartbeat();

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Heartbeat received')
            ->assertJsonPath('data.node_id', 'node-01')
            ->assertJsonPath('data.status', 'ok');

        $this->assertDatabaseHas('monitored_nodes', [
            'node_id' => 'node-01',
            'current_status' => 'ok',
        ]);

        $this->assertDatabaseHas('heartbeat_events', [
            'node_id' => 'node-01',
            'status' => 'ok',
            'signature_valid' => 1,
        ]);
    }

    public function test_rejects_invalid_internal_backend_signature(): void
    {
        $response = $this->sendInternalHeartbeat(signature: 'bad-signature');

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Invalid heartbeat signature');

        $this->assertDatabaseHas('heartbeat_events', [
            'node_id' => 'node-01',
            'status' => 'unknown',
            'signature_valid' => 0,
        ]);
    }

    public function test_status_endpoint_returns_down_when_last_heartbeat_is_null(): void
    {
        MonitoredNode::query()->create([
            'node_id' => 'node-unknown',
            'name' => 'Node Internal A',
            'current_status' => 'ok',
            'heartbeat_status' => 'ok',
            'last_heartbeat_at' => null,
            'timeout_threshold_seconds' => 180,
        ]);

        $response = $this->getJson('/api/v1/status/node-unknown');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.server_name', 'Node Internal A')
            ->assertJsonPath('data.status', 'down')
            ->assertJsonPath('data.reason_code', 'heartbeat_timeout')
            ->assertJsonPath(
                'data.message',
                'Heartbeat Node Internal A tidak diterima dalam batas waktu. Server dinyatakan down.'
            );
    }

    public function test_status_endpoint_returns_down_when_heartbeat_is_stale(): void
    {
        MonitoredNode::query()->create([
            'node_id' => 'node-stale',
            'name' => 'Server Produksi 1',
            'current_status' => 'ok',
            'heartbeat_status' => 'ok',
            'last_heartbeat_at' => now('UTC')->subMinutes(10),
            'timeout_threshold_seconds' => 180,
        ]);

        $response = $this->getJson('/api/v1/status/node-stale');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.server_name', 'Server Produksi 1')
            ->assertJsonPath('data.status', 'down')
            ->assertJsonPath('data.reason_code', 'heartbeat_timeout')
            ->assertJsonPath(
                'data.message',
                'Heartbeat Server Produksi 1 tidak diterima dalam batas waktu. Server dinyatakan down.'
            );
    }

    public function test_status_endpoint_uses_dynamic_name_from_payload_when_available(): void
    {
        MonitoredNode::query()->create([
            'node_id' => 'node-dynamic',
            'name' => 'Fallback Name',
            'current_status' => 'down',
            'heartbeat_status' => 'ok',
            'last_heartbeat_at' => null,
            'timeout_threshold_seconds' => 180,
            'last_payload_json' => [
                'server_name' => 'Node-Region-JKT-01',
            ],
        ]);

        $response = $this->getJson('/api/v1/status/node-dynamic');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.server_name', 'Node-Region-JKT-01')
            ->assertJsonPath(
                'data.message',
                'Heartbeat Node-Region-JKT-01 tidak diterima dalam batas waktu. Server dinyatakan down.'
            );
    }

    public function test_status_endpoint_returns_degraded_when_probe_not_configured(): void
    {
        config()->set('monitoring.internal_probe_url_map', []);

        MonitoredNode::query()->create([
            'node_id' => 'node-no-probe',
            'name' => 'Node No Probe',
            'current_status' => 'ok',
            'heartbeat_status' => 'ok',
            'last_heartbeat_at' => now('UTC'),
            'timeout_threshold_seconds' => 180,
        ]);

        $response = $this->getJson('/api/v1/status/node-no-probe');

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'degraded')
            ->assertJsonPath('data.reason_code', 'probe_not_configured')
            ->assertJsonPath(
                'data.message',
                'URL probe untuk Node No Probe belum dikonfigurasi di external monitoring.'
            );
    }

    public function test_status_endpoint_returns_degraded_when_probe_failed_after_threshold(): void
    {
        Http::fake([
            'https://internal.example/health' => Http::sequence()
                ->pushStatus(503)
                ->pushStatus(503),
        ]);

        $this->sendHeartbeat(payloadOverrides: ['overall_status' => 'ok'])->assertStatus(200);

        app(TimeoutCheckerService::class)->checkTimeouts();
        app(TimeoutCheckerService::class)->checkTimeouts();

        $response = $this->getJson('/api/v1/status/node-01');

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'degraded')
            ->assertJsonPath('data.reason_code', 'isp_down')
            ->assertJsonPath('data.connectivity.state', 'unreachable')
            ->assertJsonPath('data.connectivity.fail_count', 2);
    }
}
