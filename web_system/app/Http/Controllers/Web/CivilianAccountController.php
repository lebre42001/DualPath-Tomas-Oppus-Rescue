<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\CivilianAccountAudit;
use App\Models\IncidentReport;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CivilianAccountController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->redirectIfCivilian($request->user())) {
            return $redirect;
        }

        $search = trim((string) $request->query('search', ''));
        $statusFilter = (string) $request->query('status', 'all');
        $deviceFilter = (string) $request->query('device', 'all');

        $activeStatuses = ['received', 'acknowledged', 'dispatched', 'responding'];
        $allCivilianAccounts = User::query()
            ->where('role', 'civilian')
            ->with(['responderProfile', 'blockedByResponder'])
            ->withCount('submittedReports')
            ->latest('created_at')
            ->latest('id')
            ->get();

        $this->attachAccessMetadata($allCivilianAccounts);

        $filteredCivilianAccounts = $allCivilianAccounts
            ->filter(fn (User $civilian): bool => $this->matchesSearch($civilian, $search))
            ->filter(fn (User $civilian): bool => $this->matchesStatusFilter($civilian, $statusFilter))
            ->filter(fn (User $civilian): bool => $this->matchesDeviceFilter($civilian, $deviceFilter))
            ->values();

        $currentPage = max(1, (int) $request->integer('page', 1));
        $civilianAccounts = new LengthAwarePaginator(
            $filteredCivilianAccounts->forPage($currentPage, 3)->values(),
            $filteredCivilianAccounts->count(),
            3,
            $currentPage,
            [
                'path' => route('civilian-accounts.index'),
                'query' => $request->except('page'),
            ]
        );

        $auditLogs = $this->auditLogs($search);

        return view('civilian-accounts.index', [
            'civilianAccounts' => $civilianAccounts,
            'filters' => [
                'search' => $search,
                'status' => $statusFilter,
                'device' => $deviceFilter,
            ],
            'auditLogs' => $auditLogs,
            'stats' => [
                'active' => IncidentReport::query()->whereIn('status', $activeStatuses)->count(),
                'civilian_accounts' => $allCivilianAccounts->count(),
                'filtered_accounts' => $filteredCivilianAccounts->count(),
                'updated_today' => User::query()->where('role', 'civilian')->whereDate('updated_at', now()->toDateString())->count(),
                'missing_phone' => $allCivilianAccounts->filter(static fn (User $civilian): bool => blank($civilian->responderProfile?->phone))->count(),
                'blocked_accounts' => $allCivilianAccounts->filter(static fn (User $civilian): bool => $civilian->isBlocked())->count(),
                'tracked_devices' => $allCivilianAccounts->filter(static fn (User $civilian): bool => filled($civilian->access_ip_address ?? null))->count(),
                'audit_entries_today' => CivilianAccountAudit::query()->whereDate('created_at', now()->toDateString())->count(),
            ],
            'fatalAlerts' => IncidentReport::query()->operationallyAssessed()
                ->whereIn('severity', ['Critical', 'Fatal'])
                ->whereIn('status', $activeStatuses)
                ->latest()
                ->take(5)
                ->get(),
        ]);
    }

    public function verificationImage(Request $request, User $civilianAccount): StreamedResponse
    {
        if ($request->user()?->isCivilian()) {
            abort(403);
        }

        abort_unless($civilianAccount->isCivilian(), 404);
        abort_unless($civilianAccount->account_verification_selfie_path && Storage::exists($civilianAccount->account_verification_selfie_path), 404);

        return Storage::response(
            $civilianAccount->account_verification_selfie_path,
            'account-verification-'.$civilianAccount->id.'.jpg',
        );
    }

    public function block(Request $request, User $civilianAccount): RedirectResponse
    {
        if ($redirect = $this->redirectIfCivilian($request->user())) {
            return $redirect;
        }

        abort_unless($civilianAccount->isCivilian(), 404);

        $validated = $request->validate([
            'block_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $isBlocking = ! $civilianAccount->isBlocked();

        DB::transaction(function () use ($request, $civilianAccount, $validated, $isBlocking): void {
            $reason = $isBlocking
                ? (filled($validated['block_reason'] ?? null) ? $validated['block_reason'] : 'Blocked by admin account control.')
                : null;

            $civilianAccount->forceFill([
                'blocked_at' => $isBlocking ? now() : null,
                'blocked_by' => $isBlocking ? $request->user()?->id : null,
                'blocked_reason' => $reason,
                'api_token' => null,
            ])->save();

            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $civilianAccount->id)->delete();
            }

            $this->logAudit(
                $request,
                $isBlocking ? 'blocked' : 'unblocked',
                $civilianAccount,
                $isBlocking ? ($reason ?: 'Civilian account blocked.') : 'Civilian account access restored.',
                [
                    'blocked' => $isBlocking,
                    'reason' => $reason,
                ],
            );
        });

        $message = $isBlocking
            ? 'Civilian account blocked successfully. Web sessions and API tokens were cleared.'
            : 'Civilian account unblocked successfully.';

        return redirect()
            ->to(route('civilian-accounts.index').'#civilian-account-'.$civilianAccount->id)
            ->with('status', $message);
    }

    public function destroy(Request $request, User $civilianAccount): RedirectResponse
    {
        if ($redirect = $this->redirectIfCivilian($request->user())) {
            return $redirect;
        }

        abort_unless($civilianAccount->isCivilian(), 404);

        $submittedReportCount = $civilianAccount->submittedReports()->count();
        $civilianName = $civilianAccount->name;

        DB::transaction(function () use ($civilianAccount, $request, $submittedReportCount): void {
            $this->logAudit(
                $request,
                'deleted',
                $civilianAccount,
                $submittedReportCount > 0
                    ? 'Deleted civilian account and preserved existing incident history.'
                    : 'Deleted civilian account without linked incident history.',
                [
                    'submitted_reports' => $submittedReportCount,
                ],
            );

            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $civilianAccount->id)->delete();
            }

            $civilianAccount->responderProfile()?->delete();
            $civilianAccount->delete();
        });

        $message = $submittedReportCount > 0
            ? $civilianName.' was deleted. Existing report history was preserved but unlinked from the removed account.'
            : $civilianName.' was deleted successfully.';

        return redirect()
            ->route('civilian-accounts.index')
            ->with('status', $message);
    }

    private function attachAccessMetadata(EloquentCollection $civilianAccounts): void
    {
        $latestSessions = $this->latestSessionSnapshots($civilianAccounts->modelKeys());

        foreach ($civilianAccounts as $civilianAccount) {
            $sessionSnapshot = $latestSessions->get($civilianAccount->id);
            $userAgent = $sessionSnapshot->user_agent ?? $civilianAccount->last_login_user_agent;
            $ipAddress = $sessionSnapshot->ip_address ?? $civilianAccount->last_login_ip_address;
            $lastSeenAt = isset($sessionSnapshot->last_activity)
                ? Carbon::createFromTimestamp((int) $sessionSnapshot->last_activity)
                : $civilianAccount->last_login_at;

            $civilianAccount->setAttribute('access_device_label', $this->deviceLabel($userAgent));
            $civilianAccount->setAttribute('access_ip_address', $ipAddress);
            $civilianAccount->setAttribute('access_user_agent', $userAgent);
            $civilianAccount->setAttribute('access_last_seen_human', $lastSeenAt?->diffForHumans());
            $civilianAccount->setAttribute('access_last_seen_exact', $lastSeenAt?->format('M d, Y h:i A'));
            $civilianAccount->setAttribute('has_live_session', $sessionSnapshot !== null);
        }
    }

    private function latestSessionSnapshots(array $userIds)
    {
        if ($userIds === [] || ! Schema::hasTable('sessions')) {
            return collect();
        }

        return DB::table('sessions')
            ->select('user_id', 'ip_address', 'user_agent', 'last_activity')
            ->whereNotNull('user_id')
            ->whereIn('user_id', $userIds)
            ->orderByDesc('last_activity')
            ->get()
            ->groupBy('user_id')
            ->map(static fn ($sessions) => $sessions->first());
    }

    private function auditLogs(string $search)
    {
        return CivilianAccountAudit::query()
            ->with('responder')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($builder) use ($search): void {
                    $builder
                        ->where('target_name', 'like', '%'.$search.'%')
                        ->orWhere('target_email', 'like', '%'.$search.'%')
                        ->orWhere('target_phone', 'like', '%'.$search.'%')
                        ->orWhere('notes', 'like', '%'.$search.'%')
                        ->orWhereHas('responder', function ($responderQuery) use ($search): void {
                            $responderQuery
                                ->where('name', 'like', '%'.$search.'%')
                                ->orWhere('email', 'like', '%'.$search.'%');
                        });
                });
            })
            ->latest()
            ->take(18)
            ->get()
            ->map(function (CivilianAccountAudit $audit) {
                $audit->setAttribute('actor_device_label', $this->deviceLabel($audit->user_agent));
                $audit->setAttribute('action_label', match ($audit->action) {
                    'updated' => 'Updated account details',
                    'blocked' => 'Blocked account access',
                    'unblocked' => 'Restored account access',
                    'deleted' => 'Deleted civilian account',
                    default => Str::headline($audit->action),
                });
                $audit->setAttribute('action_tone', match ($audit->action) {
                    'blocked', 'deleted' => 'red',
                    'updated' => 'blue',
                    'unblocked' => 'green',
                    default => 'neutral',
                });

                return $audit;
            });
    }

    private function logAudit(Request $request, string $action, User $civilianAccount, string $notes, array $context = []): void
    {
        CivilianAccountAudit::query()->create([
            'civilian_account_id' => $civilianAccount->id,
            'responder_id' => $request->user()?->id,
            'action' => $action,
            'target_name' => $civilianAccount->name,
            'target_email' => $civilianAccount->email,
            'target_phone' => $civilianAccount->responderProfile?->phone,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
            'notes' => $notes,
            'context' => $context,
        ]);
    }

    private function matchesSearch(User $civilian, string $search): bool
    {
        if ($search === '') {
            return true;
        }

        $haystacks = [
            $civilian->name,
            $civilian->email,
            $civilian->responderProfile?->phone,
            $civilian->access_ip_address,
            $civilian->access_device_label,
            $civilian->blocked_reason,
        ];

        $needle = Str::lower($search);

        foreach ($haystacks as $value) {
            if (filled($value) && str_contains(Str::lower((string) $value), $needle)) {
                return true;
            }
        }

        return false;
    }

    private function matchesStatusFilter(User $civilian, string $statusFilter): bool
    {
        return match ($statusFilter) {
            'blocked' => $civilian->isBlocked(),
            'active' => ! $civilian->isBlocked(),
            'verified' => $civilian->hasVerifiedAccount(),
            'unverified' => ! $civilian->hasVerifiedAccount(),
            'missing-phone' => blank($civilian->responderProfile?->phone),
            'live-session' => (bool) ($civilian->has_live_session ?? false),
            default => true,
        };
    }

    private function matchesDeviceFilter(User $civilian, string $deviceFilter): bool
    {
        $label = Str::lower((string) ($civilian->access_device_label ?? ''));

        return match ($deviceFilter) {
            'mobile' => str_contains($label, 'iphone') || str_contains($label, 'android phone'),
            'desktop' => str_contains($label, 'windows pc') || str_contains($label, 'mac') || str_contains($label, 'linux pc'),
            'no-device' => blank($civilian->access_user_agent ?? null),
            default => true,
        };
    }

    private function deviceLabel(?string $userAgent): string
    {
        if (blank($userAgent)) {
            return 'No device captured yet';
        }

        $agent = Str::lower($userAgent);

        $device = match (true) {
            str_contains($agent, 'iphone') => 'iPhone',
            str_contains($agent, 'ipad') => 'iPad',
            str_contains($agent, 'android') && str_contains($agent, 'mobile') => 'Android phone',
            str_contains($agent, 'android') => 'Android tablet',
            str_contains($agent, 'windows') => 'Windows PC',
            str_contains($agent, 'macintosh'), str_contains($agent, 'mac os x') => 'Mac',
            str_contains($agent, 'linux') => 'Linux PC',
            default => 'Unknown device',
        };

        $browser = match (true) {
            str_contains($agent, 'edg') => 'Edge',
            str_contains($agent, 'opr'), str_contains($agent, 'opera') => 'Opera',
            str_contains($agent, 'firefox') => 'Firefox',
            str_contains($agent, 'chrome'), str_contains($agent, 'crios') => 'Chrome',
            str_contains($agent, 'safari') => 'Safari',
            default => 'Browser',
        };

        return $device.' / '.$browser;
    }

    private function redirectIfCivilian(?User $user): ?RedirectResponse
    {
        if ($user?->isCivilian()) {
            return redirect()
                ->route('dashboard')
                ->with('status', 'Civilian account management is only available to admin accounts.');
        }

        return null;
    }

}
