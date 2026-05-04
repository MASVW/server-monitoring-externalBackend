<?php

namespace Tests\Feature;

use App\Models\HeartbeatEvent;
use App\Models\MonitoredNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertJsonPath('data.current_status', 'degraded')
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'node_id',
                    'current_status',
                    'last_heartbeat_at',
                    'heartbeat_interval_seconds',
                    'timeout_threshold_seconds',
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
}
