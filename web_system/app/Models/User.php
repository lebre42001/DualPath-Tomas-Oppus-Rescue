<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable([
    'name',
    'email',
    'email_verified_at',
    'google_id',
    'google_avatar_url',
    'google_linked_at',
    'password',
    'is_admin',
    'role',
    'account_verified_at',
    'account_verification_selfie_path',
    'account_verification_method',
    'blocked_at',
    'blocked_by',
    'blocked_reason',
    'last_login_at',
    'last_login_ip_address',
    'last_login_user_agent',
])]
#[Hidden(['password', 'remember_token', 'api_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'google_linked_at' => 'datetime',
            'account_verified_at' => 'datetime',
            'is_admin' => 'boolean',
            'password' => 'hashed',
            'last_login_at' => 'datetime',
            'blocked_at' => 'datetime',
        ];
    }

    public function responderProfile(): HasOne
    {
        return $this->hasOne(ResponderProfile::class);
    }

    public function submittedReports(): HasMany
    {
        return $this->hasMany(IncidentReport::class, 'reported_by');
    }

    public function assignedReports(): HasMany
    {
        return $this->hasMany(IncidentReport::class, 'assigned_responder_id');
    }

    public function blockedByResponder(): BelongsTo
    {
        return $this->belongsTo(self::class, 'blocked_by');
    }

    public function civilianAccountAudits(): HasMany
    {
        return $this->hasMany(CivilianAccountAudit::class, 'civilian_account_id');
    }

    public function performedCivilianAccountAudits(): HasMany
    {
        return $this->hasMany(CivilianAccountAudit::class, 'responder_id');
    }

    public function isResponder(): bool
    {
        return $this->role === 'responder';
    }

    public function isCivilian(): bool
    {
        return $this->role === 'civilian';
    }

    public function hasVerifiedAccount(): bool
    {
        return ! $this->isCivilian() || $this->account_verified_at !== null;
    }

    public function isBlocked(): bool
    {
        return $this->blocked_at !== null;
    }
}
