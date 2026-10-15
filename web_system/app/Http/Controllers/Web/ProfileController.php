<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\IncidentReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProfileController extends Controller
{
    public function show(): View
    {
        $user = Auth::user();
        $profile = $user?->responderProfile;
        $reportsQuery = IncidentReport::query()->where('reported_by', $user?->id);

        return view('profile.show', [
            'user' => $user,
            'profile' => $profile,
            'profilePhotoUrl' => $user?->profile_photo_path ? route('profile.photo') : null,
            'reportStats' => [
                'total' => (clone $reportsQuery)->count(),
                'active' => (clone $reportsQuery)->whereIn('status', ['received', 'acknowledged', 'dispatched', 'responding'])->count(),
                'completed' => (clone $reportsQuery)->where('status', 'resolved')->count(),
                'latest' => (clone $reportsQuery)->latest()->first(),
            ],
            'recentReports' => (clone $reportsQuery)->latest()->take(4)->get(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user, 403);

        $profile = $user->responderProfile()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'assigned_station' => $user->role === 'civilian' ? 'Civilian Mobile' : 'Field Access',
                'connectivity_mode' => 'auto_select',
                'notification_profile' => $user->role === 'civilian' ? 'critical_alerts' : 'priority_only',
            ]
        );

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:40'],
            'assigned_station' => ['nullable', 'string', 'max:120'],
            'emergency_contact_name' => ['nullable', 'string', 'max:120'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:40'],
            'profile_photo' => ['nullable', 'file', 'max:4096', 'mimes:jpg,jpeg,png,webp'],
            'remove_profile_photo' => ['nullable', 'boolean'],
            'password' => ['nullable', 'confirmed', Password::min(6)],
        ]);

        $user->forceFill([
            'name' => $validated['name'],
            'email' => $validated['email'],
        ]);

        $removeProfilePhoto = $request->boolean('remove_profile_photo');

        if ($removeProfilePhoto && $user->profile_photo_path && ! $request->hasFile('profile_photo')) {
            Storage::delete($user->profile_photo_path);
            $user->profile_photo_path = null;
        }

        if ($request->hasFile('profile_photo')) {
            if ($user->profile_photo_path) {
                Storage::delete($user->profile_photo_path);
            }

            $user->profile_photo_path = $request->file('profile_photo')->store('profile-photos');
        }

        if (! empty($validated['password'])) {
            $user->password = $validated['password'];
        }

        $user->save();

        $profile->fill([
            'phone' => $validated['phone'] ?? null,
            'assigned_station' => $validated['assigned_station'] ?: ($user->role === 'civilian' ? 'Civilian Mobile' : 'Field Access'),
            'emergency_contact_name' => $validated['emergency_contact_name'] ?? null,
            'emergency_contact_phone' => $validated['emergency_contact_phone'] ?? null,
        ])->save();

        return redirect()
            ->route('profile.show')
            ->with('status', 'Profile updated successfully.');
    }

    public function photo(Request $request): StreamedResponse
    {
        $user = $request->user();

        abort_unless(
            $user && $user->profile_photo_path && Storage::exists($user->profile_photo_path),
            404
        );

        return Storage::response($user->profile_photo_path);
    }
}
