<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoraNodeLocation extends Model
{
    protected $fillable = ['node_id', 'location_label', 'latitude', 'longitude'];

    protected function casts(): array
    {
        return ['latitude' => 'decimal:7', 'longitude' => 'decimal:7'];
    }
}
