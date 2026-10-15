<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class AccountVerificationController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if (! $user?->isCivilian()) {
            return redirect()->route('dashboard');
        }

        if ($user->hasVerifiedAccount()) {
            return redirect()->route('dashboard')->with('status', 'Your account is already verified.');
        }

        return view('account.verify', ['user' => $user]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user?->isCivilian()) {
            return redirect()->route('dashboard');
        }

        if ($user->hasVerifiedAccount()) {
            return redirect()->route('dashboard')->with('status', 'Your account is already verified.');
        }

        $validated = $request->validate([
            'verification_selfie' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp'],
        ], [
            'verification_selfie.required' => 'Capture a live front-camera selfie before verifying your account.',
        ]);

        if ($user->account_verification_selfie_path) {
            Storage::delete($user->account_verification_selfie_path);
        }

        $path = $validated['verification_selfie']->store('account-verifications');

        $user->forceFill([
            'account_verified_at' => now(),
            'account_verification_selfie_path' => $path,
            'account_verification_method' => 'live_front_camera_selfie',
        ])->save();

        return redirect()
            ->route('dashboard')
            ->with('status', 'Account verified. Send Report is now unlocked.');
    }
}
