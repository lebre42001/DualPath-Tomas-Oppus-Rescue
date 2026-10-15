<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $profile = $request->user()->responderProfile;
        $notificationProfile = collect(explode(',', (string) ($profile?->notification_profile ?? 'critical_alerts,push_notifications')))
            ->filter()
            ->values();

        $data = [
                'critical_alerts' => $notificationProfile->contains('critical_alerts'),
                'push_notifications' => $notificationProfile->contains('push_notifications'),
                'sms_backup' => $notificationProfile->contains('sms_backup'),
                'storage' => 'mysql',
        ];

        if (! $request->user()->isCivilian()) {
            $data['connectivity_mode'] = $profile?->connectivity_mode ?? 'auto_select';
        }

        return response()->json(['data' => $data]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'critical_alerts' => ['required', 'boolean'],
            'push_notifications' => ['required', 'boolean'],
            'sms_backup' => ['required', 'boolean'],
            'connectivity_mode' => ['nullable', 'string', 'max:40'],
        ]);

        $user = $request->user();
        $profile = $user->responderProfile()->firstOrCreate([], [
            'assigned_station' => 'Bontoc HQ',
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

        $data = [
            'critical_alerts' => str_contains($notificationProfile, 'critical_alerts'),
            'push_notifications' => str_contains($notificationProfile, 'push_notifications'),
            'sms_backup' => str_contains($notificationProfile, 'sms_backup'),
            'storage' => 'mysql',
        ];

        if (! $user->isCivilian()) {
            $data['connectivity_mode'] = $profile->connectivity_mode;
        }

        return response()->json([
            'message' => 'Settings updated successfully.',
            'data' => $data,
        ]);
    }
}
