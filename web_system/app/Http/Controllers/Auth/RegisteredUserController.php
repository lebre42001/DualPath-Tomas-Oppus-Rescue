<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ResponderProfile;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:40'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Password::min(6)],
        ]);

        $user = DB::transaction(function () use ($validated): User {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'role' => 'civilian',
                'is_admin' => false,
            ]);

            ResponderProfile::create([
                'user_id' => $user->id,
                'phone' => $validated['phone'],
                'assigned_station' => 'Civilian Mobile',
                'emergency_contact_name' => $validated['name'].' Emergency Contact',
                'emergency_contact_phone' => $validated['phone'],
                'connectivity_mode' => 'auto_select',
                'notification_profile' => 'push_notifications,sms_backup',
            ]);

            return $user;
        });

        event(new Registered($user));
        Auth::login($user);
        $this->recordLoginMetadata($user, $request);

        return redirect()->route('account.verify');
    }

    private function recordLoginMetadata(User $user, Request $request): void
    {
        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip_address' => $request->ip(),
            'last_login_user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
        ])->save();
    }
}
