<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CivilianAccountAudit extends Model
{
    use HasFactory;

    protected $fillable = [
        'civilian_account_id',
        'responder_id',
        'action',
        'target_name',
        'target_email',
        'target_phone',
        'ip_address',
        'user_agent',
        'notes',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
        ];
    }

    public function civilianAccount(): BelongsTo
    {
        return $this->belongsTo(User::class, 'civilian_account_id');
    }

    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responder_id');
    }
}
