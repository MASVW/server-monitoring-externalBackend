<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IncidentEvent extends Model
{
    protected $table = 'incident_events';

    protected $fillable = [
        'node_id',
        'from_status',
        'to_status',
        'event_type',
        'message',
        'metadata_json',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata_json' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
