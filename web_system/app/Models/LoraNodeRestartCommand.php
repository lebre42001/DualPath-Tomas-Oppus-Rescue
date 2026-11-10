<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoraNodeRestartCommand extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime', 'expires_at' => 'datetime',
            'sent_at' => 'datetime', 'accepted_at' => 'datetime',
            'last_offered_at' => 'datetime',
            'restarted_at' => 'datetime', 'failed_at' => 'datetime',
        ];
    }
}
