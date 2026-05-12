<?php

namespace Tests\Feature;

use App\Models\MonitoredNode;
use App\Services\AlertService;
use App\Services\WhatsAppAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AlertMessageAlignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_discord_embed_contains_reason_and_connectivity_fields(): void
    {
        config()->set('discord.enabled', true);
        config()->set('discord.bot_token', 'bot-token');
        config()->set('discord.channel_id', '123456789');
        config()->set('discord.api_base_url', 'https://discord.test/api/v10');
        config()->set('whatsapp.enabled', false);

        MonitoredNode::query()->create([
            'node_id' => 'node-01',
            'name' => 'Node-01',
            'current_status' => 'degraded',
            'heartbeat_status' => 'ok',
            'reason_code' => 'isp_down',
            'probe_state' => 'unreachable',
            'probe_fail_count' => 2,
            'last_probe_error' => 'HTTP 503',
            'last_heartbeat_at' => now('UTC'),
            'last_summary_json' => [
                'host' => [],
                'services' => ['list' => []],
                'problems' => [],
            ],
        ]);

        Http::fake([
            'https://discord.test/*' => Http::response(['id' => 'ok'], 200),
        ]);

        app(AlertService::class)->sendAlert([
            'node_id' => 'node-01',
            'from_status' => 'ok',
            'to_status' => 'degraded',
            'event_type' => 'isp_unreachable',
            'reason_code' => 'isp_down',
            'message' => 'Tidak ditemukan informasi Node-01. Kemungkinan ISP down...',
            'connectivity' => [
                'probe_url' => 'https://internal-monitoring.indraangkola.com/health',
                'state' => 'unreachable',
                'fail_count' => 2,
                'failure_threshold' => 2,
                'last_checked_at' => now('UTC')->toIso8601String(),
                'last_ok_at' => null,
                'last_error' => 'HTTP 503',
            ],
        ]);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/channels/123456789/messages')) {
                return false;
            }

            $embeds = $request->data()['embeds'] ?? [];
            if (! is_array($embeds) || $embeds === []) {
                return false;
            }

            $fields = $embeds[0]['fields'] ?? [];
            $fieldNames = collect($fields)->pluck('name')->all();

            return in_array('⚠️ Keterangan', $fieldNames, true)
                && in_array('🌐 Connectivity Probe', $fieldNames, true);
        });
    }

    public function test_whatsapp_message_contains_reason_and_connectivity_sections(): void
    {
        config()->set('whatsapp.enabled', true);
        config()->set('whatsapp.provider', 'fonnte');
        config()->set('whatsapp.fonnte.api_url', 'https://fonnte.test/send');
        config()->set('whatsapp.fonnte.token', 'token');
        config()->set('whatsapp.fonnte.target_group_id', 'group@g.us');
        config()->set('discord.enabled', false);

        Http::fake([
            'https://fonnte.test/*' => Http::response(['status' => true], 200),
        ]);

        app(WhatsAppAlertService::class)->sendIncidentAlert([
            'node_id' => 'node-01',
            'server_name' => 'Node-01',
            'status' => 'degraded',
            'reason_code' => 'isp_down',
            'message' => 'Tidak ditemukan informasi Node-01. Kemungkinan ISP down...',
            'summary' => [
                'host' => [],
                'services' => ['list' => []],
                'problems' => [],
            ],
            'connectivity' => [
                'probe_url' => 'https://internal-monitoring.indraangkola.com/health',
                'state' => 'unreachable',
                'fail_count' => 2,
                'failure_threshold' => 2,
                'last_checked_at' => now('UTC')->toIso8601String(),
                'last_ok_at' => null,
                'last_error' => 'HTTP 503',
            ],
        ]);

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://fonnte.test/send') {
                return false;
            }

            $message = (string) ($request->data()['message'] ?? '');

            return str_contains($message, '⚠️ *Keterangan*')
                && str_contains($message, '🌐 *Connectivity Probe*')
                && str_contains($message, 'Kemungkinan ISP down');
        });
    }
}

