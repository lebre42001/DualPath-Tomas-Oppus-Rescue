<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminDutyShift extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime', 'last_activity_at' => 'datetime'];
    }
}
