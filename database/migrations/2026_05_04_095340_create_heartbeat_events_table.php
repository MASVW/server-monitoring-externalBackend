<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('heartbeat_events', function (Blueprint $table): void {
            $table->id();
            $table->string('node_id', 120);
            $table->timestamp('received_at');
            $table->timestamp('node_timestamp');
            $table->enum('status', ['ok', 'degraded', 'down', 'unknown']);
            $table->json('payload_json')->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->string('ip_address', 120)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();

            $table->index(['node_id', 'received_at'], 'idx_heartbeat_events_node_received');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('heartbeat_events');
    }
};
