<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SendsHeartbeatRequests;
use Tests\TestCase;

class IncidentsApiTest extends TestCase
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
    }

    public function test_lists_incidents_with_pagination_and_filters(): void
    {
        $this->sendHeartbeat(payloadOverrides: ['overall_status' => 'ok'])->assertStatus(200);
        $this->sendHeartbeat(payloadOverrides: ['overall_status' => 'degraded'])->assertStatus(200);
        $this->sendHeartbeat(payloadOverrides: ['overall_status' => 'ok'])->assertStatus(200);

        $response = $this->getJson('/api/v1/admin/incidents?node_id=node-01&page=1&limit=20');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.limit', 20)
            ->assertJsonStructure([
                'success',
                'message',
                'data',
                'meta' => ['page', 'limit', 'total', 'total_pages'],
            ]);

        $data = $response->json('data');
        $this->assertNotEmpty($data);

        $eventTypes = collect($data)->pluck('event_type')->all();
        $this->assertContains('degraded', $eventTypes);
        $this->assertContains('recovered', $eventTypes);
    }
}
