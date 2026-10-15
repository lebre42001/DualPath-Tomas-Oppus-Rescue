<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

class AdminResponderSeeder extends Seeder
{
    public function run(): void
    {
        $email = trim((string) config('admin.seed.email'));
        $password = (string) config('admin.seed.password');

        if ($email === '' || $password === '') {
            $this->command?->warn('Admin responder seed skipped: set ADMIN_SEED_EMAIL and ADMIN_SEED_PASSWORD explicitly.');

            return;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($password) < 12) {
            throw new InvalidArgumentException('Admin responder seed credentials must include a valid email and a password of at least 12 characters.');
        }

        $admin = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => (string) config('admin.seed.name', 'Admin Receiver'),
                'password' => Hash::make($password),
                'is_admin' => true,
                'role' => 'admin',
            ]
        );

        $admin->responderProfile()->updateOrCreate(
            ['user_id' => $admin->id],
            [
                'phone' => null,
                'assigned_station' => (string) config('admin.seed.station', 'Bontoc Command Center'),
                'emergency_contact_name' => null,
                'emergency_contact_phone' => null,
                'connectivity_mode' => 'auto_select',
                'notification_profile' => 'critical_alerts,push_notifications,sms_backup',
            ]
        );
    }
}
