<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_alert_states', function (Blueprint $table): void {
            $table->id();
            $table->string('node_id', 120);
            $table->string('channel', 40);
            $table->enum('current_status', ['ok', 'degraded', 'down', 'unknown'])->default('unknown');
            $table->string('incident_key', 255)->nullable();
            $table->timestamp('incident_started_at')->nullable();
            $table->timestamp('last_alert_at')->nullable();
            $table->timestamp('last_reminder_at')->nullable();
            $table->timestamp('recovered_at')->nullable();
            $table->timestamp('recovery_notified_at')->nullable();
            $table->json('last_payload_json')->nullable();
            $table->timestamps();

            $table->unique(['node_id', 'channel'], 'uniq_alert_state_node_channel');
            $table->index(['channel', 'current_status'], 'idx_alert_state_channel_status');
            $table->index(['channel', 'last_reminder_at'], 'idx_alert_state_channel_reminder');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_alert_states');
    }
};
