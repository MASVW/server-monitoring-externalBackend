<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HeartbeatEvent extends Model
{
    protected $table = 'heartbeat_events';

    protected $fillable = [
        'node_id',
        'received_at',
        'node_timestamp',
        'status',
        'payload_json',
        'signature_valid',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'node_timestamp' => 'datetime',
            'payload_json' => 'array',
            'signature_valid' => 'boolean',
        ];
    }
}
