<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ResponderProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileApiController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = $user->responderProfile;

        return response()->json(['data' => $this->profilePayload($user, $profile)]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'station' => ['nullable', 'string', 'max:120'],
        ]);

        $user = $request->user();
        $user->update([
            'name' => $validated['name'],
        ]);

        $profile = $user->responderProfile()->firstOrCreate([], [
            'assigned_station' => $user->isCivilian() ? 'Civilian Mobile' : 'Bontoc HQ',
            'connectivity_mode' => 'auto_select',
            'notification_profile' => $user->isCivilian()
                ? 'push_notifications,sms_backup'
                : 'critical_alerts,push_notifications,sms_backup',
        ]);

        $profile->update([
            'phone' => $validated['phone'] ?? $profile->phone,
            'assigned_station' => $user->isCivilian()
                ? 'Civilian Mobile'
                : ($validated['station'] ?? $profile->assigned_station),
        ]);

        return response()->json([
            'message' => 'Profile updated successfully.',
            'data' => $this->profilePayload($user, $profile),
        ]);
    }

    private function profilePayload(User $user, ?ResponderProfile $profile): array
    {
        $payload = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'is_admin' => (bool) $user->is_admin,
            'phone' => $profile?->phone,
            'notification_profile' => $profile?->notification_profile ?? 'critical_alerts,push_notifications',
        ];

        if (! $user->isCivilian()) {
            $payload['station'] = $profile?->assigned_station ?? 'Bontoc HQ';
            $payload['connectivity_mode'] = $profile?->connectivity_mode ?? 'auto_select';
        }

        return $payload;
    }
}
