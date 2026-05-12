<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonitoredNode extends Model
{
    protected $table = 'monitored_nodes';

    protected $fillable = [
        'node_id',
        'name',
        'heartbeat_interval_seconds',
        'timeout_threshold_seconds',
        'secret_reference',
        'heartbeat_status',
        'current_status',
        'reason_code',
        'probe_state',
        'probe_fail_count',
        'last_heartbeat_at',
        'last_probe_checked_at',
        'last_probe_ok_at',
        'last_probe_error',
        'last_payload_json',
        'last_summary_json',
        'last_alert_at',
    ];

    protected function casts(): array
    {
        return [
            'last_heartbeat_at' => 'datetime',
            'last_probe_checked_at' => 'datetime',
            'last_probe_ok_at' => 'datetime',
            'probe_fail_count' => 'integer',
            'last_payload_json' => 'array',
            'last_summary_json' => 'array',
            'last_alert_at' => 'datetime',
        ];
    }
}
