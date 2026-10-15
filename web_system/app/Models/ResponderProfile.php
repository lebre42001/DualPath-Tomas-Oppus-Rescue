<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResponderProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'phone',
        'assigned_station',
        'emergency_contact_name',
        'emergency_contact_phone',
        'connectivity_mode',
        'notification_profile',
    ];
}