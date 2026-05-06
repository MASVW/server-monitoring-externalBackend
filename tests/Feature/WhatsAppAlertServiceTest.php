<?php

namespace Tests\Feature;

use App\Models\MonitoringAlertState;
use App\Services\WhatsAppAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppAlertServiceTest extends TestCase
{
    use RefreshDatabase;

    private WhatsAppAlertService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('whatsapp.enabled', true);
        config()->set('whatsapp.provider', 'fonnte');
        config()->set('whatsapp.reminder_interval_minutes', 60);
        config()->set('whatsapp.timezone', 'Asia/Jakarta');
        config()->set('whatsapp.fonnte.api_url', 'https://api.fonnte.com/send');
        config()->set('whatsapp.fonnte.token', 'test-token');
        config()->set('whatsapp.fonnte.target_group_id', '12345@g.us');

        Http::fake([
            'https://api.fonnte.com/send' => Http::response([
                'status' => true,
                'detail' => 'ok',
            ], 200),
        ]);

        $this->service = app(WhatsAppAlertService::class);
    }

    public function test_it_sends_initial_incident_and_persists_state(): void
    {
        $result = $this->service->sendIncidentAlert($this->context(status: 'degraded'));

        $this->assertTrue((bool) ($result['sent'] ?? false));

        $this->assertDatabaseHas('monitoring_alert_states', [
            'node_id' => 'node-01',
            'channel' => 'whatsapp',
            'current_status' => 'degraded',
            'incident_key' => 'node:node-01',
        ]);

        Http::assertSentCount(1);
    }

    public function test_it_prevents_duplicate_initial_alert_for_active_incident(): void
    {
        $this->service->sendIncidentAlert($this->context(status: 'down'));

        $result = $this->service->sendIncidentAlert($this->context(status: 'down'));

        $this->assertFalse((bool) ($result['sent'] ?? false));
        $this->assertSame('incident_already_active', $result['reason'] ?? null);

        Http::assertSentCount(1);
    }

    public function test_it_sends_reminder_only_after_configured_interval(): void
    {
        $this->service->sendIncidentAlert($this->context(status: 'down'));

        $notDue = $this->service->sendReminderAlert($this->context(status: 'down'));

        $this->assertFalse((bool) ($notDue['sent'] ?? false));
        $this->assertSame('reminder_not_due', $notDue['reason'] ?? null);

        MonitoringAlertState::query()
            ->where('node_id', 'node-01')
            ->where('channel', 'whatsapp')
            ->update([
                'last_alert_at' => now('UTC')->subMinutes(61),
                'last_reminder_at' => now('UTC')->subMinutes(61),
            ]);

        $due = $this->service->sendReminderAlert($this->context(status: 'down'));

        $this->assertTrue((bool) ($due['sent'] ?? false));
        Http::assertSentCount(2);
    }

    public function test_it_sends_recovery_once_per_incident(): void
    {
        $this->service->sendIncidentAlert($this->context(status: 'degraded'));

        $recovery = $this->service->sendRecoveryAlert($this->context(status: 'ok', summary: $this->healthySummary()));

        $this->assertTrue((bool) ($recovery['sent'] ?? false));

        $state = MonitoringAlertState::query()
            ->where('node_id', 'node-01')
            ->where('channel', 'whatsapp')
            ->first();

        $this->assertNotNull($state);
        $this->assertNotNull($state?->recovery_notified_at);

        $secondRecovery = $this->service->sendRecoveryAlert($this->context(status: 'ok', summary: $this->healthySummary()));

        $this->assertFalse((bool) ($secondRecovery['sent'] ?? false));
        $this->assertSame('no_active_incident', $secondRecovery['reason'] ?? null);

        Http::assertSentCount(2);
    }

    private function context(string $status, ?array $summary = null): array
    {
        return [
            'node_id' => 'node-01',
            'status' => $status,
            'previous_status' => $status === 'ok' ? 'down' : 'ok',
            'summary' => $summary ?? $this->degradedSummary(),
            'last_heartbeat_at' => now('UTC')->toIso8601String(),
            'timeout_threshold_seconds' => 180,
        ];
    }

    private function degradedSummary(): array
    {
        return [
            'host' => [
                'cpu' => ['loadPercent' => 22.2, 'cores' => 4],
                'memory' => ['usedBytes' => 3_000_000_000, 'totalBytes' => 8_000_000_000, 'usedPercent' => 37.5],
                'disk' => [
                    ['mountPoint' => '/', 'usedPercent' => 20, 'usedBytes' => 10_000_000_000, 'sizeBytes' => 50_000_000_000],
                ],
                'uptime' => 86_500,
                'hostname' => 'host-1',
                'platform' => 'linux',
            ],
            'services' => [
                'list' => [
                    ['name' => 'api-server', 'status' => 'online'],
                    ['name' => 'worker', 'status' => 'down'],
                ],
            ],
            'problems' => [],
        ];
    }

    private function healthySummary(): array
    {
        return [
            'host' => [
                'cpu' => ['loadPercent' => 10.0, 'cores' => 4],
                'memory' => ['usedBytes' => 2_000_000_000, 'totalBytes' => 8_000_000_000, 'usedPercent' => 25.0],
                'disk' => [
                    ['mountPoint' => '/', 'usedPercent' => 18, 'usedBytes' => 9_000_000_000, 'sizeBytes' => 50_000_000_000],
                ],
                'uptime' => 90_000,
                'hostname' => 'host-1',
                'platform' => 'linux',
            ],
            'services' => [
                'list' => [
                    ['name' => 'api-server', 'status' => 'online'],
                    ['name' => 'worker', 'status' => 'online'],
                ],
            ],
            'problems' => [],
        ];
    }
}
