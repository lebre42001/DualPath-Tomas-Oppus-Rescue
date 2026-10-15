<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function index(): View
    {
        $user = Auth::user();
        $profile = $user?->responderProfile;
        $notificationProfile = collect(explode(',', (string) ($profile?->notification_profile ?? 'critical_alerts,push_notifications')))
            ->filter()
            ->values()
            ->all();

        return view('settings.index', [
            'user' => $user,
            'profile' => $profile,
            'settings' => [
                'critical_alerts' => in_array('critical_alerts', $notificationProfile, true),
                'push_notifications' => in_array('push_notifications', $notificationProfile, true),
                'sms_backup' => in_array('sms_backup', $notificationProfile, true),
                'connectivity_mode' => $profile?->connectivity_mode ?? 'auto_select',
            ],
        ]);
    }

    public function readiness(): View
    {
        $user = Auth::user();

        return view('settings.readiness', [
            'user' => $user,
            'isCivilian' => $user?->isCivilian() ?? false,
        ]);
    }

    public function update(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'critical_alerts' => ['required', 'boolean'],
            'push_notifications' => ['required', 'boolean'],
            'sms_backup' => ['required', 'boolean'],
            'connectivity_mode' => ['nullable', 'string', 'max:40'],
        ]);

        $user = $request->user();
        $profile = $user->responderProfile()->firstOrCreate([], [
            'phone' => null,
            'assigned_station' => 'Bontoc HQ',
            'emergency_contact_name' => $user->name.' Emergency Contact',
            'emergency_contact_phone' => null,
            'connectivity_mode' => 'auto_select',
            'notification_profile' => 'critical_alerts,push_notifications',
        ]);

        $notificationProfile = collect([
            'critical_alerts' => (bool) $validated['critical_alerts'],
            'push_notifications' => (bool) $validated['push_notifications'],
            'sms_backup' => (bool) $validated['sms_backup'],
        ])->filter()->keys()->implode(',');

        $profile->update([
            'notification_profile' => $notificationProfile,
            'connectivity_mode' => $user->isCivilian()
                ? 'auto_select'
                : ($validated['connectivity_mode'] ?? $profile->connectivity_mode),
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'saved' => true,
                'notification_profile' => $notificationProfile,
                'connectivity_mode' => $profile->connectivity_mode,
            ]);
        }

        return back();
    }
}
