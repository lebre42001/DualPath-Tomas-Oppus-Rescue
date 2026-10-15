<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ResponderProfile;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GoogleAuthController extends Controller
{
    private const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const USERINFO_URL = 'https://www.googleapis.com/oauth2/v3/userinfo';

    public function redirect(Request $request): RedirectResponse
    {
        $config = $this->googleConfig();

        if (! $this->googleIsConfigured($config)) {
            return $this->loginError('Google sign-in is not configured yet. Add the Google Client ID and Secret first.');
        }

        $state = Str::random(48);
        $request->session()->put('google_oauth_state', $state);

        return redirect()->away(self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => $config['client_id'],
            'redirect_uri' => $config['redirect'],
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986));
    }

    public function callback(Request $request): RedirectResponse
    {
        $config = $this->googleConfig();

        if (! $this->googleIsConfigured($config)) {
            return $this->loginError('Google sign-in is not configured yet. Add the Google Client ID and Secret first.');
        }

        if ($request->filled('error')) {
            return $this->loginError('Google sign-in was cancelled or denied. Please try again.');
        }

        $expectedState = $request->session()->pull('google_oauth_state');
        $receivedState = (string) $request->query('state', '');

        if (! is_string($expectedState) || $expectedState === '' || ! hash_equals($expectedState, $receivedState)) {
            return $this->loginError('Google sign-in expired. Please try again.');
        }

        $code = (string) $request->query('code', '');

        if ($code === '') {
            return $this->loginError('Google did not return a sign-in code. Please try again.');
        }

        $tokenResponse = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'redirect_uri' => $config['redirect'],
            'grant_type' => 'authorization_code',
            'code' => $code,
        ]);

        if (! $tokenResponse->successful() || ! is_string($tokenResponse->json('access_token'))) {
            return $this->loginError('Google sign-in could not be completed. Please try again.');
        }

        $profileResponse = Http::withToken((string) $tokenResponse->json('access_token'))
            ->acceptJson()
            ->get(self::USERINFO_URL);

        if (! $profileResponse->successful()) {
            return $this->loginError('Google account details could not be loaded. Please try again.');
        }

        $profile = $profileResponse->json();
        $googleId = trim((string) data_get($profile, 'sub', ''));
        $email = Str::lower(trim((string) data_get($profile, 'email', '')));
        $name = trim((string) data_get($profile, 'name', ''));
        $avatarUrl = trim((string) data_get($profile, 'picture', ''));
        $emailVerified = filter_var(data_get($profile, 'email_verified', false), FILTER_VALIDATE_BOOL);

        if ($googleId === '' || $email === '' || ! $emailVerified) {
            return $this->loginError('Use a verified Google account email to continue.');
        }

        $user = User::query()
            ->where('google_id', $googleId)
            ->orWhere('email', $email)
            ->first();

        if ($user && (! $user->isCivilian() || $user->is_admin)) {
            return $this->loginError('This Google account is not authorized for civilian sender access.');
        }

        if ($user?->isBlocked()) {
            return $this->loginError('This civilian account has been blocked by an admin. Please contact Bontoc Rescue for assistance.');
        }

        $user = DB::transaction(function () use ($user, $googleId, $email, $name, $avatarUrl): User {
            if (! $user) {
                $user = User::create([
                    'name' => $name !== '' ? $name : Str::before($email, '@'),
                    'email' => $email,
                    'email_verified_at' => now(),
                    'password' => Hash::make(Str::random(64)),
                    'google_id' => $googleId,
                    'google_avatar_url' => $avatarUrl !== '' ? $avatarUrl : null,
                    'google_linked_at' => now(),
                    'role' => 'civilian',
                    'is_admin' => false,
                ]);
            } else {
                $emailInUseByAnotherAccount = User::query()
                    ->where('email', $email)
                    ->whereKeyNot($user->id)
                    ->exists();

                $user->forceFill([
                    'name' => $user->name ?: ($name !== '' ? $name : Str::before($email, '@')),
                    'email' => $emailInUseByAnotherAccount ? $user->email : $email,
                    'email_verified_at' => $user->email_verified_at ?? now(),
                    'google_id' => $user->google_id ?: $googleId,
                    'google_avatar_url' => $avatarUrl !== '' ? $avatarUrl : $user->google_avatar_url,
                    'google_linked_at' => $user->google_linked_at ?? now(),
                ])->save();
            }

            ResponderProfile::query()->firstOrCreate(
                ['user_id' => $user->id],
                [
                    'phone' => null,
                    'assigned_station' => 'Civilian Mobile',
                    'emergency_contact_name' => $user->name.' Emergency Contact',
                    'emergency_contact_phone' => null,
                    'connectivity_mode' => 'auto_select',
                    'notification_profile' => 'push_notifications,sms_backup',
                ],
            );

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();
        $this->recordLoginMetadata($user, $request);

        return redirect()->intended(
            $user->hasVerifiedAccount() ? route('reports.create') : route('account.verify')
        );
    }

    /**
     * @return array{client_id: ?string, client_secret: ?string, redirect: ?string}
     */
    private function googleConfig(): array
    {
        return [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect' => config('services.google.redirect'),
        ];
    }

    /**
     * @param  array{client_id: ?string, client_secret: ?string, redirect: ?string}  $config
     */
    private function googleIsConfigured(array $config): bool
    {
        return filled($config['client_id'])
            && filled($config['client_secret'])
            && filled($config['redirect']);
    }

    private function loginError(string $message): RedirectResponse
    {
        return redirect()
            ->route('login')
            ->withErrors(['identity' => $message]);
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
