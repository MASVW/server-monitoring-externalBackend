<?php

namespace Tests\Unit;

use App\Models\MonitoredNode;
use App\Services\NodeReasonService;
use Tests\TestCase;

class NodeReasonServiceTest extends TestCase
{
    public function test_prefers_node_identity_over_hostname_when_server_name_is_missing(): void
    {
        $service = new NodeReasonService();
        $node = new MonitoredNode([
            'node_id' => 'node-01',
            'name' => 'node-01',
        ]);

        $serverName = $service->resolveServerName(
            $node,
            payload: [
                'host' => ['hostname' => '9204b20e0625'],
            ],
            summary: [
                'host' => ['hostname' => '9204b20e0625'],
            ]
        );

        $this->assertSame('node-01', $serverName);
    }

    public function test_uses_payload_server_name_when_available(): void
    {
        $service = new NodeReasonService();
        $node = new MonitoredNode([
            'node_id' => 'node-01',
            'name' => 'node-01',
        ]);

        $serverName = $service->resolveServerName(
            $node,
            payload: [
                'server_name' => 'Internal Production A',
                'host' => ['hostname' => '9204b20e0625'],
            ]
        );

        $this->assertSame('Internal Production A', $serverName);
    }

    public function test_falls_back_to_hostname_if_node_identity_is_not_available(): void
    {
        $service = new NodeReasonService();

        $serverName = $service->resolveServerName(
            node: null,
            payload: [
                'host' => ['hostname' => '9204b20e0625'],
            ]
        );

        $this->assertSame('9204b20e0625', $serverName);
    }
}
