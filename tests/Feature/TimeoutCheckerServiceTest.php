<?php

namespace Tests\Feature;

use App\Models\IncidentEvent;
use App\Models\MonitoredNode;
use App\Services\TimeoutCheckerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimeoutCheckerServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_timeout_checker_creates_dynamic_undetected_message(): void
    {
        MonitoredNode::query()->create([
            'node_id' => 'node-timeout-1',
            'name' => 'Server Internal A',
            'current_status' => 'ok',
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
            'Tidak ditemukan informasi Server Internal A. Kemungkinan ISP down, server internal down, service heartbeat mati, atau external tidak menerima sinyal.',
            $incident->message
        );
    }
}

