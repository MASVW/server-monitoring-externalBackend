<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_events', function (Blueprint $table): void {
            $table->id();
            $table->string('node_id', 120);
            $table->enum('from_status', ['ok', 'degraded', 'down', 'unknown'])->nullable();
            $table->enum('to_status', ['ok', 'degraded', 'down', 'unknown']);
            $table->enum('event_type', ['degraded', 'down', 'recovered', 'heartbeat_received', 'timeout_detected']);
            $table->string('message', 500);
            $table->json('metadata_json')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['node_id', 'occurred_at'], 'idx_incident_events_node_occurred');
            $table->index(['event_type'], 'idx_incident_events_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_events');
    }
};
