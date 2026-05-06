<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonitoringAlertState extends Model
{
    protected $table = 'monitoring_alert_states';

    protected $fillable = [
        'node_id',
        'channel',
        'current_status',
        'incident_key',
        'incident_started_at',
        'last_alert_at',
        'last_reminder_at',
        'recovered_at',
        'recovery_notified_at',
        'last_payload_json',
    ];

    protected function casts(): array
    {
        return [
            'incident_started_at' => 'datetime',
            'last_alert_at' => 'datetime',
            'last_reminder_at' => 'datetime',
            'recovered_at' => 'datetime',
            'recovery_notified_at' => 'datetime',
            'last_payload_json' => 'array',
        ];
    }
}
