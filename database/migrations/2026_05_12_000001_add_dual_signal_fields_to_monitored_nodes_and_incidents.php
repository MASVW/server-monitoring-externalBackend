<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('monitored_nodes')) {
            Schema::table('monitored_nodes', function (Blueprint $table): void {
                if (! Schema::hasColumn('monitored_nodes', 'heartbeat_status')) {
                    $table->enum('heartbeat_status', ['ok', 'degraded', 'down', 'unknown'])
                        ->default('unknown')
                        ->after('secret_reference');
                }

                if (! Schema::hasColumn('monitored_nodes', 'reason_code')) {
                    $table->string('reason_code', 80)
                        ->default('unknown')
                        ->after('current_status');
                }

                if (! Schema::hasColumn('monitored_nodes', 'probe_state')) {
                    $table->enum('probe_state', ['unknown', 'reachable', 'unreachable', 'unconfigured'])
                        ->default('unknown')
                        ->after('reason_code');
                }

                if (! Schema::hasColumn('monitored_nodes', 'probe_fail_count')) {
                    $table->unsignedInteger('probe_fail_count')
                        ->default(0)
                        ->after('probe_state');
                }

                if (! Schema::hasColumn('monitored_nodes', 'last_probe_checked_at')) {
                    $table->timestamp('last_probe_checked_at')
                        ->nullable()
                        ->after('last_heartbeat_at');
                }

                if (! Schema::hasColumn('monitored_nodes', 'last_probe_ok_at')) {
                    $table->timestamp('last_probe_ok_at')
                        ->nullable()
                        ->after('last_probe_checked_at');
                }

                if (! Schema::hasColumn('monitored_nodes', 'last_probe_error')) {
                    $table->string('last_probe_error', 500)
                        ->nullable()
                        ->after('last_probe_ok_at');
                }
            });
        }

        if (Schema::hasTable('incident_events') && DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE incident_events MODIFY event_type ENUM('degraded','down','recovered','heartbeat_received','timeout_detected','isp_unreachable','isp_recovered') NOT NULL"
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('monitored_nodes')) {
            Schema::table('monitored_nodes', function (Blueprint $table): void {
                $columns = [
                    'heartbeat_status',
                    'reason_code',
                    'probe_state',
                    'probe_fail_count',
                    'last_probe_checked_at',
                    'last_probe_ok_at',
                    'last_probe_error',
                ];

                foreach ($columns as $column) {
                    if (Schema::hasColumn('monitored_nodes', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('incident_events') && DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE incident_events MODIFY event_type ENUM('degraded','down','recovered','heartbeat_received','timeout_detected') NOT NULL"
            );
        }
    }
};

