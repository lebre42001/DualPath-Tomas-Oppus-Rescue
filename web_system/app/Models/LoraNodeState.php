<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoraNodeState extends Model
{
    protected $fillable = [
        'node_id',
        'gateway_id',
        'last_seen_at',
        'last_known_route',
        'known_peers',
        'battery',
        'hop',
        'ttl',
        'rssi',
        'last_packet_type',
        'snr',
        'round_trip_ms',
        'round_trip_sample',
        'round_trip_measured_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'known_peers' => 'array',
            'snr' => 'float',
            'round_trip_ms' => 'integer',
            'round_trip_measured_at' => 'datetime',
        ];
    }
}
