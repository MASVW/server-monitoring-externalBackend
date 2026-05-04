<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitored_nodes', function (Blueprint $table): void {
            $table->id();
            $table->string('node_id', 120)->unique();
            $table->string('name', 255)->default('Unnamed Node');
            $table->unsignedInteger('heartbeat_interval_seconds')->default(60);
            $table->unsignedInteger('timeout_threshold_seconds')->default(180);
            $table->string('secret_reference', 255)->nullable();
            $table->enum('current_status', ['ok', 'degraded', 'down', 'unknown'])->default('unknown');
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->json('last_payload_json')->nullable();
            $table->json('last_summary_json')->nullable();
            $table->timestamp('last_alert_at')->nullable();
            $table->timestamps();

            $table->index(['node_id'], 'idx_monitored_nodes_node_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitored_nodes');
    }
};
