<?php

namespace Tests\Unit;

use App\Services\StateTransitionService;
use Tests\TestCase;

class StateTransitionServiceTest extends TestCase
{
    public function test_resolves_recovered_transition(): void
    {
        $service = new StateTransitionService();

        $transition = $service->resolveHeartbeatTransition('down', 'ok');

        $this->assertNotNull($transition);
        $this->assertSame('recovered', $transition['event_type']);
    }

    public function test_resolves_degraded_transition_from_ok(): void
    {
        $service = new StateTransitionService();

        $transition = $service->resolveHeartbeatTransition('ok', 'degraded');

        $this->assertNotNull($transition);
        $this->assertSame('degraded', $transition['event_type']);
    }

    public function test_timeout_transition_marks_node_down(): void
    {
        $service = new StateTransitionService();

        $transition = $service->resolveTimeoutTransition('ok');

        $this->assertNotNull($transition);
        $this->assertSame('timeout_detected', $transition['event_type']);
        $this->assertSame('down', $transition['to_status']);
    }
}
