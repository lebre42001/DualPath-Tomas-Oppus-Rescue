<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthApiController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
            'role' => ['nullable', 'in:civilian'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => 'civilian',
            'is_admin' => false,
        ]);

        $user->responderProfile()->create([
            'phone' => $validated['phone'] ?? null,
            'assigned_station' => 'Civilian Mobile',
            'emergency_contact_name' => $validated['name'].' Emergency Contact',
            'emergency_contact_phone' => $validated['phone'] ?? null,
            'connectivity_mode' => 'auto_select',
            'notification_profile' => 'push_notifications,sms_backup',
        ]);

        $this->recordLoginMetadata($user, $request);
        $token = $this->issueToken($user);

        return response()->json([
            'message' => 'Account created successfully.',
            'token' => $token,
            'user' => $this->userPayload($user->fresh('responderProfile')),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        return $this->loginForPortal($request, 'civilian');
    }

    public function responderLogin(Request $request): JsonResponse
    {
        return $this->adminLogin($request);
    }

    public function adminLogin(Request $request): JsonResponse
    {
        return $this->loginForPortal($request, 'admin');
    }

    private function loginForPortal(Request $request, string $portal): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:6'],
        ]);

        $user = User::query()->where('email', $validated['email'])->first();

        if ($portal === 'civilian' && $user?->isBlocked()) {
            return response()->json([
                'message' => 'This civilian account has been blocked by an admin. Please contact Bontoc Rescue for assistance.',
            ], 423);
        }

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid email or password.',
            ], 422);
        }

        $isAdminPortal = in_array($portal, ['admin', 'responder'], true);

        $hasPortalAccess = $isAdminPortal
            ? (bool) $user->is_admin
            : $user->isCivilian();

        if (! $hasPortalAccess) {
            return response()->json([
                'message' => $isAdminPortal
                    ? 'This account is not authorized for admin receiver access.'
                    : 'Admin accounts must use the admin login endpoint.',
            ], 403);
        }

        $this->recordLoginMetadata($user, $request);
        $token = $this->issueToken($user);

        return response()->json([
            'message' => $isAdminPortal ? 'Admin login successful.' : 'Login successful.',
            'token' => $token,
            'user' => $this->userPayload($user->fresh('responderProfile')),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'user' => $this->userPayload($user->fresh('responderProfile')),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['api_token' => null])->save();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    private function issueToken(User $user): string
    {
        $plainTextToken = Str::random(80);

        $user->forceFill([
            'api_token' => hash('sha256', $plainTextToken),
        ])->save();

        return $plainTextToken;
    }

    private function recordLoginMetadata(User $user, Request $request): void
    {
        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip_address' => $request->ip(),
            'last_login_user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
        ])->save();
    }

    private function userPayload(User $user): array
    {
        $profile = $user->responderProfile;

        $payload = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->is_admin ? 'admin' : $user->role,
            'is_admin' => (bool) $user->is_admin,
            'account_verified' => $user->hasVerifiedAccount(),
            'account_verified_at' => optional($user->account_verified_at)->toIso8601String(),
            'phone' => $profile?->phone,
            'notification_profile' => $profile?->notification_profile ?? 'push_notifications',
        ];

        if (! $user->isCivilian()) {
            $payload['station'] = $profile?->assigned_station ?? 'Bontoc HQ';
            $payload['connectivity_mode'] = $profile?->connectivity_mode ?? 'auto_select';
        }

        return $payload;
    }
}
