<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login', ['portal' => 'civilian']);
    }

    public function createResponder(): View
    {
        return $this->createAdmin();
    }

    public function createAdmin(): View
    {
        return view('auth.login', ['portal' => 'admin']);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->authenticate($request, 'civilian');
    }

    public function storeResponder(Request $request): RedirectResponse
    {
        return $this->storeAdmin($request);
    }

    public function storeAdmin(Request $request): RedirectResponse
    {
        return $this->authenticate($request, 'admin');
    }

    private function authenticate(Request $request, string $portal): RedirectResponse
    {
        $credentials = $request->validate([
            'identity' => ['required', 'string', 'email'],
            'password' => ['required', 'string', 'min:6'],
        ]);

        $user = User::query()->where('email', $credentials['identity'])->first();

        if ($portal === 'civilian' && $user?->isBlocked()) {
            throw ValidationException::withMessages([
                'identity' => 'This civilian account has been blocked by an admin. Please contact Bontoc Rescue for assistance.',
            ]);
        }

        if (! Auth::attempt([
            'email' => $credentials['identity'],
            'password' => $credentials['password'],
        ], $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'identity' => 'The provided account credentials could not be verified.',
            ]);
        }

        /** @var User|null $user */
        $user = $request->user();

        $isAdminPortal = in_array($portal, ['admin', 'responder'], true);

        $hasPortalAccess = $isAdminPortal
            ? (bool) $user?->is_admin
            : $user?->isCivilian();

        if (! $hasPortalAccess) {
            Auth::logout();

            throw ValidationException::withMessages([
                'identity' => $isAdminPortal
                    ? 'This account is not authorized for admin receiver access.'
                    : 'Admin accounts must use the admin receiver portal.',
            ]);
        }

        $request->session()->regenerate();

        if ($user) {
            $this->recordLoginMetadata($user, $request);
        }

        $targetUrl = $user?->is_admin
            ? route('admin.dashboard')
            : ($user?->isCivilian() ? route('reports.create') : route('dashboard'));

        return redirect()->intended($targetUrl);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $wasAdmin = (bool) $request->user()?->is_admin;

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route($wasAdmin ? 'admin.login' : 'welcome');
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
