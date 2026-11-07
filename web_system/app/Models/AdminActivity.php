<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AdminActivity extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['context' => 'array', 'occurred_at' => 'datetime', 'imported' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('System log entries cannot be edited.'));
        static::deleting(fn () => throw new LogicException('System log entries cannot be deleted.'));
    }
}
