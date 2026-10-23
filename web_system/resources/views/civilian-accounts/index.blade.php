@extends('layouts.app')

@section('title', 'Civilian Accounts')
@section('page_label', 'Admin Access Control')
@section('page_heading', 'Civilian Accounts')
@section('page_subheading', 'Review verification, account access, and civilian activity.')

@section('content')
    <section class="panel admin-account-filters" aria-labelledby="civilian-filter-heading">
        <div class="panel-head">
            <div>
                <h2 class="panel-title" id="civilian-filter-heading">Search &amp; Filters</h2>
            </div>
        </div>

        <form method="GET" action="{{ route('civilian-accounts.index') }}" class="incident-actions admin-account-filter-form">
            <div class="form-grid admin-account-filter-fields">
                <div class="field">
                    <label for="civilian-search">Search accounts</label>
                    <input
                        id="civilian-search"
                        class="input"
                        type="text"
                        name="search"
                        value="{{ $filters['search'] }}"
                        placeholder="Name, email, phone, IP address, or device"
                    >
                </div>
                <div class="field">
                    <label for="civilian-status-filter">Account status</label>
                    <select id="civilian-status-filter" class="input" name="status">
                        <option value="all" @selected($filters['status'] === 'all')>All accounts</option>
                        <option value="active" @selected($filters['status'] === 'active')>Active only</option>
                        <option value="blocked" @selected($filters['status'] === 'blocked')>Blocked only</option>
                        <option value="verified" @selected($filters['status'] === 'verified')>Verified only</option>
                        <option value="unverified" @selected($filters['status'] === 'unverified')>Unverified only</option>
                        <option value="missing-phone" @selected($filters['status'] === 'missing-phone')>Missing contact number</option>
                        <option value="online" @selected(in_array($filters['status'], ['online', 'live-session'], true))>Online now</option>
                        <option value="offline" @selected($filters['status'] === 'offline')>Offline now</option>
                    </select>
                </div>
                <div class="field">
                    <label for="civilian-device-filter">Device type</label>
                    <select id="civilian-device-filter" class="input" name="device">
                        <option value="all" @selected($filters['device'] === 'all')>All devices</option>
                        <option value="mobile" @selected($filters['device'] === 'mobile')>Mobile devices</option>
                        <option value="desktop" @selected($filters['device'] === 'desktop')>Desktop devices</option>
                        <option value="no-device" @selected($filters['device'] === 'no-device')>No device data yet</option>
                    </select>
                </div>
            </div>
            <div class="button-row">
                <button type="submit" class="btn btn-primary">Search</button>
                <a href="{{ route('civilian-accounts.index') }}" class="btn btn-secondary">Reset</a>
            </div>
        </form>
    </section>

    <section class="panel admin-account-directory" data-civilian-presence-url="{{ route('civilian-accounts.presence') }}">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">Civilian directory</h2>
                <p class="section-copy">Select View to review details and manage account access. <span data-presence-refresh-status role="status">Checking presence…</span></p>
            </div>
            <button type="button" class="btn btn-secondary" data-civilian-directory-open aria-haspopup="dialog" aria-controls="civilian-directory-dialog">View all</button>
        </div>

        @if ($civilianAccounts->count() === 0)
            <div class="data-empty">No civilian accounts matched the current search or filter.</div>
        @else
            <div class="civilian-account-list">
                @foreach ($civilianAccounts as $civilianAccount)
                    @php
                        $isBlocked = $civilianAccount->isBlocked();
                        $isVerified = $civilianAccount->hasVerifiedAccount();
                        $hasLiveSession = (bool) ($civilianAccount->has_live_session ?? false);
                        $verificationTone = $isVerified ? 'green' : ($isBlocked ? 'red' : 'amber');
                        $verificationLabel = $isVerified ? 'Verified' : 'Not Verified';
                        $verificationImageUrl = $civilianAccount->account_verification_selfie_path
                            ? route('civilian-accounts.verification-image', $civilianAccount)
                            : null;
                        $deviceLabel = $civilianAccount->access_device_label ?? 'No device captured yet';
                        $ipAddress = $civilianAccount->access_ip_address ?: 'No IP captured yet';
                        $userAgent = $civilianAccount->access_user_agent ?: 'No browser or device signature captured yet.';
                        $lastSeenHuman = $civilianAccount->access_last_seen_human ?: 'No app activity yet';
                        $lastSeenExact = $civilianAccount->access_last_seen_exact ?: 'Not available';
                        $blockReasonValue = old('block_form_id') == $civilianAccount->id
                            ? old('block_reason', $civilianAccount->blocked_reason)
                            : $civilianAccount->blocked_reason;
                    @endphp
                    <article class="civilian-account-row" id="civilian-account-{{ $civilianAccount->id }}" data-civilian-account-row data-civilian-account-id="{{ $civilianAccount->id }}" data-civilian-account-title="{{ e($civilianAccount->name.' - '.$civilianAccount->email) }}">
                        <div class="civilian-account-row-summary">
                            <span class="civilian-account-row-main">
                                <span class="civilian-account-status-dot {{ $verificationTone }}"></span>
                                <span>
                                    <strong>{{ $civilianAccount->name }}</strong>
                                    <small>{{ $civilianAccount->email }}</small>
                                </span>
                            </span>
                            <span class="civilian-account-row-meta">
                                <span class="tag {{ $verificationTone }}">{{ $verificationLabel }}</span>
                                @if ($isBlocked)
                                    <span class="tag red">Blocked</span>
                                @else
                                    <span class="tag green">Login Allowed</span>
                                @endif
                                <span class="tag {{ $hasLiveSession ? 'blue' : 'neutral' }}" data-account-presence="{{ $civilianAccount->id }}" data-presence-badge>{{ $hasLiveSession ? 'Online' : 'Offline' }}</span>
                                <span class="tag neutral">{{ $civilianAccount->submitted_reports_count }} Reports</span>
                                <button type="button" class="btn btn-secondary civilian-account-view-button" data-civilian-account-modal-trigger>View</button>
                            </span>
                        </div>

                        <div class="incident-card compact-live-card civilian-account-card civilian-account-modal-source">
                            <div class="incident-rail {{ $isBlocked ? 'severity-Fatal' : ($isVerified ? 'severity-Minor' : 'severity-Serious') }}"></div>
                            <div class="incident-meta">
                                <span class="incident-code">Civilian Account</span>
                                <span class="incident-time">{{ $civilianAccount->name }}</span>
                                <span class="tag {{ $verificationTone }}">{{ $verificationLabel }}</span>
                                <span class="tag neutral">{{ $civilianAccount->submitted_reports_count }} Reports</span>
                            </div>
                            <div class="incident-content">
                            <div class="incident-headline">
                                <div>
                                    <h3 class="incident-title">{{ $civilianAccount->name }}</h3>
                                    <p class="incident-summary">
                                        Email: {{ $civilianAccount->email }}<br>
                                        Phone: {{ $civilianAccount->responderProfile?->phone ?? 'No contact saved' }}<br>
                                        Verification: {{ $isVerified ? 'Verified on '.optional($civilianAccount->account_verified_at)->format('M d, Y h:i A') : 'Not verified yet' }}<br>
                                        Last seen: <span data-presence-last-seen="{{ $civilianAccount->id }}">{{ $lastSeenHuman }}</span><br>
                                        Updated: {{ optional($civilianAccount->updated_at)->format('M d, Y h:i A') ?? 'Not available' }}
                                    </p>
                                </div>
                                <div class="tag-row">
                                    <span class="tag {{ $hasLiveSession ? 'blue' : 'neutral' }}" data-account-presence="{{ $civilianAccount->id }}" data-presence-badge>{{ $hasLiveSession ? 'Online' : 'Offline' }}</span>
                                    <span class="tag {{ $verificationTone }}">{{ $verificationLabel }}</span>
                                    <span class="tag {{ $isBlocked ? 'red' : 'green' }}">{{ $isBlocked ? 'Login Blocked' : 'Login Allowed' }}</span>
                                </div>
                            </div>

                            <div class="preview-grid civilian-account-summary-grid">
                                <article class="detail-card civilian-verification-card">
                                    <span class="incident-code">Facial Verification Image</span>
                                    <strong>{{ $isVerified ? 'Captured verification selfie' : 'No verification image yet' }}</strong>
                                    @if ($verificationImageUrl)
                                        <img src="{{ $verificationImageUrl }}" alt="Facial verification image for {{ $civilianAccount->name }}" class="civilian-verification-image">
                                    @else
                                        <p>This civilian account has not completed live facial verification yet.</p>
                                    @endif
                                    <p>{{ $civilianAccount->account_verification_method ? \Illuminate\Support\Str::headline(str_replace('_', ' ', $civilianAccount->account_verification_method)) : 'Waiting for account verification.' }}</p>
                                </article>
                                <article class="detail-card">
                                    <span class="incident-code">Connection Status</span>
                                    <strong data-account-presence="{{ $civilianAccount->id }}">{{ $hasLiveSession ? 'Online' : 'Offline' }}</strong>
                                    <p data-presence-description="{{ $civilianAccount->id }}">{{ $hasLiveSession ? 'An active app connection was detected within the last 60 seconds.' : 'No current app connection detected.' }}</p>
                                </article>
                                <article class="detail-card">
                                    <span class="incident-code">Last Device Used</span>
                                    <strong>{{ $deviceLabel }}</strong>
                                    <p>{{ $userAgent }}</p>
                                </article>
                                <article class="detail-card">
                                    <span class="incident-code">Last IP Address</span>
                                    <strong>{{ $ipAddress }}</strong>
                                    <p>Captured from account access activity.</p>
                                </article>
                                <article class="detail-card">
                                    <span class="incident-code">Last activity</span>
                                    <strong data-presence-last-seen="{{ $civilianAccount->id }}">{{ $lastSeenHuman }}</strong>
                                    <p data-presence-last-seen-exact="{{ $civilianAccount->id }}">{{ $lastSeenExact }}</p>
                                </article>
                                <article class="detail-card">
                                    <span class="incident-code">Block Status</span>
                                    <strong>{{ $isBlocked ? 'Blocked by admin' : 'Access allowed' }}</strong>
                                    <p>
                                        @if ($isBlocked)
                                            {{ $civilianAccount->blocked_reason ?: 'No reason saved.' }}
                                            @if ($civilianAccount->blockedByResponder)
                                                <br>Blocked by: {{ $civilianAccount->blockedByResponder->name }}
                                            @endif
                                        @else
                                            Civilian can log in on web and API as long as credentials are valid.
                                        @endif
                                    </p>
                                </article>
                            </div>

                            <div class="divider"></div>

                            <div class="preview-grid civilian-account-action-grid">
                                <article class="detail-card">
                                    <span class="incident-code">Suspend or Restore Access</span>
                                    <strong>{{ $isBlocked ? 'Unblock this account' : 'Block this account' }}</strong>
                                    <p>{{ $isBlocked ? 'Restore login access for the civilian on web and API.' : 'Immediately revoke web sessions and API access for this civilian account.' }}</p>
                                    <form method="POST" action="{{ route('civilian-accounts.block', $civilianAccount) }}" class="incident-actions">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="block_form_id" value="{{ $civilianAccount->id }}">
                                        <div class="field">
                                            <label for="block-reason-{{ $civilianAccount->id }}">Block Reason</label>
                                            <input
                                                id="block-reason-{{ $civilianAccount->id }}"
                                                class="input"
                                                type="text"
                                                name="block_reason"
                                                value="{{ $blockReasonValue }}"
                                                placeholder="Add a short reason for blocking this civilian account"
                                            >
                                        </div>
                                        <div class="button-row">
                                            <button type="submit" class="btn {{ $isBlocked ? 'btn-secondary' : 'btn-danger' }}">{{ $isBlocked ? 'Unblock Account' : 'Block Account' }}</button>
                                        </div>
                                    </form>
                                </article>

                                <article class="detail-card">
                                    <span class="incident-code">Delete Access</span>
                                    <strong>Remove this civilian account</strong>
                                    <p>Deleting removes the civilian login and profile record. Existing incident history stays preserved but becomes unlinked from the deleted account.</p>
                                    <form method="POST" action="{{ route('civilian-accounts.destroy', $civilianAccount) }}" onsubmit="return confirm('Delete this civilian account? Existing report history will stay, but the account itself will be removed.')">
                                        @csrf
                                        @method('DELETE')
                                        <div class="button-row">
                                            <button type="submit" class="btn btn-danger">Delete Account</button>
                                        </div>
                                    </form>
                                </article>
                            </div>

                            <div class="button-row civilian-account-close-row">
                                <button type="button" class="btn btn-secondary" data-civilian-account-close>Close</button>
                            </div>
                        </div>
                        </div>
                    </article>
                @endforeach
            </div>

            @if (method_exists($civilianAccounts, 'hasPages') && $civilianAccounts->hasPages())
                <div class="report-pagination compact-pagination civilian-account-pagination">
                    <span class="report-pagination-meta">Page {{ $civilianAccounts->currentPage() }} of {{ $civilianAccounts->lastPage() }}</span>
                    <div class="button-row">
                        <a href="{{ $civilianAccounts->previousPageUrl() ?: '#' }}" class="btn btn-secondary {{ $civilianAccounts->onFirstPage() ? 'is-disabled' : '' }}">← Previous</a>
                        <a href="{{ $civilianAccounts->nextPageUrl() ?: '#' }}" class="btn btn-secondary {{ $civilianAccounts->hasMorePages() ? '' : 'is-disabled' }}">Next →</a>
                    </div>
                </div>
            @endif
        @endif
    </section>

    <section class="panel">
        <div class="panel-head">
            <div>
                <p class="eyebrow">Civilian Account Audit</p>
                <h2 class="panel-title">Account access history</h2>
                <p class="section-copy">Civilian account block, unblock, and delete actions are recorded here with the admin name, actor IP, and device label.</p>
            </div>
        </div>

        @if ($auditLogs->isEmpty())
            <div class="data-empty">No account activity matched the current search yet.</div>
        @else
            <div class="timeline">
                @foreach ($auditLogs as $audit)
                    <div class="timeline-item">
                        <div class="timeline-marker"></div>
                        <article class="timeline-copy">
                            <div class="tag-row">
                                <span class="tag {{ $audit->action_tone }}">{{ $audit->action_label }}</span>
                                <span class="incident-code">{{ optional($audit->created_at)->format('M d, Y h:i A') }}</span>
                            </div>
                            <strong>{{ $audit->target_name }}{{ $audit->target_email ? ' / '.$audit->target_email : '' }}</strong>
                            <p>
                                Admin: {{ $audit->responder?->name ?? 'Unknown admin' }}<br>
                                Actor IP: {{ $audit->ip_address ?: 'No IP recorded' }}<br>
                                Actor device: {{ $audit->actor_device_label }}<br>
                                {{ $audit->notes }}
                            </p>
                            @if (!empty($audit->context['changed_fields']))
                                <p class="meta">Changed fields: {{ implode(', ', $audit->context['changed_fields']) }}</p>
                            @elseif (array_key_exists('submitted_reports', $audit->context ?? []))
                                <p class="meta">Linked report count at delete time: {{ $audit->context['submitted_reports'] }}</p>
                            @elseif (array_key_exists('reason', $audit->context ?? []) && filled($audit->context['reason']))
                                <p class="meta">Reason: {{ $audit->context['reason'] }}</p>
                            @endif
                        </article>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    <div class="civilian-directory-modal" data-civilian-directory-modal data-accounts-url="{{ route('civilian-accounts.all') }}" hidden>
        <section class="civilian-directory-dialog" id="civilian-directory-dialog" role="dialog" aria-modal="true" aria-labelledby="civilian-directory-title" aria-describedby="civilian-directory-description" tabindex="-1">
            <header class="civilian-directory-head">
                <div>
                    <h2 id="civilian-directory-title">All civilian accounts</h2>
                    <p id="civilian-directory-description">15 accounts per page. Select View for account details.</p>
                </div>
                <button type="button" class="btn btn-secondary" data-civilian-directory-close aria-label="Close all civilian accounts">&times;</button>
            </header>
            <div class="civilian-directory-scroll" data-civilian-directory-scroll>
                <p class="civilian-directory-message" data-civilian-directory-message role="status" aria-live="polite"></p>
                <button type="button" class="btn btn-secondary" data-civilian-directory-retry hidden>Try again</button>
                <ul class="civilian-directory-list" data-civilian-directory-list aria-label="Civilian accounts"></ul>
            </div>
            <footer class="civilian-directory-footer">
                <p data-civilian-directory-count role="status" aria-live="polite">Loading accounts...</p>
                <nav aria-label="All civilian accounts pagination">
                    <button type="button" class="btn btn-secondary" data-civilian-directory-previous disabled>&larr; Previous</button>
                    <span data-civilian-directory-page>Page 1</span>
                    <button type="button" class="btn btn-secondary" data-civilian-directory-next disabled>Next &rarr;</button>
                </nav>
            </footer>
        </section>
    </div>

    <div class="civilian-account-modal" data-civilian-account-modal hidden>
        <div class="civilian-account-modal-shell" role="dialog" aria-modal="true" aria-labelledby="civilian-account-modal-title">
            <div class="civilian-account-modal-head">
                <div class="civilian-account-modal-title">
                    <p class="eyebrow">Civilian Account Review</p>
                    <strong id="civilian-account-modal-title" data-civilian-account-modal-title>Account details</strong>
                </div>
                <button type="button" class="civilian-account-modal-close" data-civilian-account-modal-close aria-label="Close civilian account details">&times;</button>
            </div>
            <div class="civilian-account-modal-body" data-civilian-account-modal-body></div>
        </div>
    </div>
@endsection
