@extends('layouts.app')

@php
    $isCivilian = $user?->isCivilian() ?? false;
    $profileTitle = $isCivilian ? 'Civilian Profile' : 'Admin Profile';
    $roleLabel = $user?->is_admin ? 'Admin Receiver' : ($isCivilian ? 'Civilian Sender' : 'Admin Receiver');
    $stationLabel = $profile?->assigned_station ?? ($isCivilian ? 'Civilian Mobile' : 'Field Access');
    $phone = $profile?->phone ?? '';
    $emergencyContactName = $profile?->emergency_contact_name ?? '';
    $emergencyContactPhone = $profile?->emergency_contact_phone ?? '';
    $callsign = $isCivilian ? 'CIV-'.str_pad((string) ($user?->id ?? 1), 3, '0', STR_PAD_LEFT) : 'RES-'.str_pad((string) ($user?->id ?? 1), 3, '0', STR_PAD_LEFT);
    $initials = collect(preg_split('/\s+/', trim((string) $user?->name)) ?: [])
        ->filter()
        ->map(static fn (string $part): string => strtoupper(substr($part, 0, 1)))
        ->take(2)
        ->implode('');
    $initials = $initials !== '' ? $initials : 'BR';
@endphp

@section('title', $profileTitle)
@section('page_label', $profileTitle)
@section('page_heading', $profileTitle)
@section('page_subheading', $isCivilian ? 'Update your name, contact number, photo, and password in one place.' : 'Update admin details, profile photo, and account security in one place.')

@section('hero')
    @if ($isCivilian)
    <section class="hero-card civilian-profile-hero" style="background:rgba(255,255,255,.96);border-color:rgba(15,31,47,.10);">
        <div class="hero-grid">
            <div class="hero-copy">
                <p class="eyebrow">Account Identity</p>
                <h2>Keep your account clear.</h2>
                <p>Keep only the important details updated so admins can identify your report and contact you fast.</p>
            </div>
                <div class="hero-metrics civilian-profile-hero-metrics">
                    <article class="metric-card"><span>Total reports</span><strong>{{ $reportStats['total'] }}</strong><p>Reports submitted.</p></article>
                    <article class="metric-card"><span>Active</span><strong>{{ $reportStats['active'] }}</strong><p>Under admin handling.</p></article>
                    <article class="metric-card"><span>Completed</span><strong>{{ $reportStats['completed'] }}</strong><p>Marked completed.</p></article>
                    <article class="metric-card"><span>Callsign</span><strong>{{ $callsign }}</strong><p>{{ $stationLabel }}</p></article>
                </div>
        </div>
    </section>
    @endif
@endsection

@section('content')
    @if ($isCivilian)
        <section class="civilian-shell civilian-profile-shell">
            <section class="summary-strip civilian-profile-shortcuts">
                <a href="#profile-form" class="summary-card" data-profile-editor-toggle>
                    <span>Details</span>
                    <strong>Account</strong>
                    <p>Name, email, and phone number.</p>
                </a>
                <a href="#profile-form" class="summary-card" data-profile-editor-toggle>
                    <span>Photo</span>
                    <strong>Picture</strong>
                    <p>Upload or capture a clear photo.</p>
                </a>
                <a href="{{ route('settings.index') }}" class="summary-card">
                    <span>Settings</span>
                    <strong>Preferences</strong>
                    <p>Alerts, device check, and display.</p>
                </a>
            </section>

            <section class="civilian-profile-grid">
                <article class="panel civilian-profile-overview-panel">
                    <div class="panel-head">
                        <div class="panel-heading">
                            <p class="panel-kicker">Profile Snapshot</p>
                            <h2 class="panel-title">Account overview</h2>
                            <p class="section-copy">Quickly check your saved identity before changing anything.</p>
                        </div>
                    </div>
                    <div class="civilian-profile-overview-layout">
                        <div class="civilian-profile-identity-card">
                            @if ($profilePhotoUrl)
                                <img src="{{ $profilePhotoUrl }}" alt="{{ $user->name }} profile picture" class="civilian-profile-avatar">
                            @else
                                <div class="civilian-profile-avatar civilian-profile-avatar-fallback">{{ $initials }}</div>
                            @endif
                            <div class="civilian-profile-identity-copy">
                                <span class="tag blue">{{ $roleLabel }}</span>
                                <strong>{{ $user->name }}</strong>
                                <p>{{ $user->email }}</p>
                                <div class="civilian-pill-list">
                                    <span class="civilian-pill">{{ $callsign }}</span>
                                    <span class="civilian-pill">{{ $stationLabel }}</span>
                                </div>
                            </div>
                        </div>

                        <article class="civilian-profile-mini-card">
                            <strong>Contact details</strong>
                            <div class="civilian-profile-detail-grid">
                                <article class="civilian-stat-card">
                                    <span>Email</span>
                                    <strong>{{ $user->email }}</strong>
                                </article>
                                <article class="civilian-stat-card">
                                    <span>Phone</span>
                                    <strong>{{ $phone !== '' ? $phone : 'Not yet configured' }}</strong>
                                </article>
                                <article class="civilian-stat-card">
                                    <span>Emergency contact</span>
                                    <strong>{{ $emergencyContactName !== '' ? $emergencyContactName : 'Not yet configured' }}</strong>
                                </article>
                                <article class="civilian-stat-card">
                                    <span>Emergency phone</span>
                                    <strong>{{ $emergencyContactPhone !== '' ? $emergencyContactPhone : 'Not yet configured' }}</strong>
                                </article>
                            </div>
                        </article>

                        <a href="#profile-form" class="civilian-action-card primary civilian-profile-update-card" data-profile-editor-toggle>
                            <span class="civilian-kicker">Update</span>
                            <strong>Update Profile</strong>
                            <p>Edit account details, contacts, photo, and password in one modal.</p>
                        </a>
                    </div>
                </article>
            </section>

        </section>
    @else
        <section class="dual-grid profile-shell-grid admin-profile-layout">
            <article class="panel admin-profile-snapshot">
                <div class="panel-head">
                    <div class="panel-heading">
                        <h2 class="panel-title">Account overview</h2>
                        <p class="section-copy">Your responder identity and contact information.</p>
                    </div>
                    <a href="#profile-form" class="btn btn-primary" data-profile-editor-toggle>Update Profile</a>
                </div>
                <div class="detail-card admin-profile-identity-card">
                    @if ($profilePhotoUrl)
                        <img class="admin-profile-avatar" src="{{ $profilePhotoUrl }}" alt="{{ $user->name }} profile picture">
                    @else
                        <div class="admin-profile-avatar" aria-hidden="true">{{ $initials }}</div>
                    @endif
                    <div class="admin-profile-identity-copy">
                        <strong>{{ $user->name }}</strong>
                        <p>{{ $user->email }}</p>
                        <span class="tag {{ $user?->is_admin ? 'red' : 'green' }}">{{ $roleLabel }}</span>
                    </div>
                </div>
                <dl class="admin-profile-report-stats" aria-label="Your report activity">
                    <div><dt>Total reports</dt><dd>{{ $reportStats['total'] }}</dd></div>
                    <div><dt>Active</dt><dd>{{ $reportStats['active'] }}</dd></div>
                    <div><dt>Completed</dt><dd>{{ $reportStats['completed'] }}</dd></div>
                </dl>
            </article>

            <div class="admin-profile-side">
                <article class="panel admin-profile-contact">
                    <h2 class="panel-title">Contact details</h2>
                    <dl class="admin-profile-contact-list">
                        <div><dt>Email</dt><dd>{{ $user->email }}</dd></div>
                        <div><dt>Phone</dt><dd>{{ $phone !== '' ? $phone : 'Not yet configured' }}</dd></div>
                        <div><dt>Assigned station</dt><dd>{{ $stationLabel }}</dd></div>
                        <div><dt>Callsign</dt><dd>{{ $callsign }}</dd></div>
                    </dl>
                </article>

                <article class="panel admin-profile-activity">
                    <h2 class="panel-title">Report activity</h2>
                    <p>Review report activity linked to your admin account.</p>
                    <div class="action-row">
                        <a href="{{ route('reports.index') }}" class="action-btn secondary">Incident Reports</a>
                        <a href="{{ route('reports.create') }}" class="action-btn secondary">Create Report</a>
                    </div>
                </article>
            </div>

        </section>
    @endif

    <div class="profile-editor-modal" data-profile-editor-modal @if (! $errors->any()) hidden @endif>
        <article id="profile-form" class="panel profile-editor-panel profile-editor-modal-shell" data-profile-editor-panel role="dialog" aria-modal="true" aria-labelledby="profile-editor-title">
            <div class="panel-head">
                <div class="panel-heading">
                    <p class="panel-kicker">Edit Profile</p>
                    <h2 class="panel-title" id="profile-editor-title">Update Profile</h2>
                    <p class="section-copy">Change only what needs updating.</p>
                </div>
                <button type="button" class="btn btn-secondary" data-profile-editor-close>Close</button>
            </div>

            <form method="POST" action="{{ route('profile.update') }}" enctype="multipart/form-data" class="stack">
                @csrf
                @method('PUT')

                <div class="capture-helper-grid">
                    <div class="form-step-card">
                        <span class="form-step-label">Identity</span>
                        <strong>Identity details</strong>
                        <div class="form-grid">
                            <div class="field">
                                <label for="name">Full name</label>
                                <input id="name" class="input" type="text" name="name" value="{{ old('name', $user->name) }}" required>
                            </div>
                            <div class="field">
                                <label for="email">Email address</label>
                                <input id="email" class="input" type="email" name="email" value="{{ old('email', $user->email) }}" required>
                            </div>
                            <div class="field">
                                <label for="assigned_station">{{ $isCivilian ? 'Community / device label' : 'Assigned station' }}</label>
                                <input id="assigned_station" class="input" type="text" name="assigned_station" value="{{ old('assigned_station', $stationLabel) }}" placeholder="{{ $isCivilian ? 'Civilian Mobile' : 'Bontoc HQ' }}">
                            </div>
                        </div>
                    </div>

                    <div class="form-step-card">
                        <span class="form-step-label">Contacts</span>
                        <strong>Contact details</strong>
                        <div class="form-grid">
                            <div class="field">
                                <label for="phone">Contact number</label>
                                <input id="phone" class="input" type="text" name="phone" value="{{ old('phone', $phone) }}" placeholder="09171234567">
                            </div>
                            <div class="field">
                                <label for="emergency_contact_name">Emergency contact name</label>
                                <input id="emergency_contact_name" class="input" type="text" name="emergency_contact_name" value="{{ old('emergency_contact_name', $emergencyContactName) }}" placeholder="Primary contact person">
                            </div>
                            <div class="field">
                                <label for="emergency_contact_phone">Emergency contact number</label>
                                <input id="emergency_contact_phone" class="input" type="text" name="emergency_contact_phone" value="{{ old('emergency_contact_phone', $emergencyContactPhone) }}" placeholder="09170000000">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-step-card">
                    <span class="form-step-label">Photo</span>
                    <strong>Change profile picture</strong>
                    <p>Upload or capture a clear profile photo. JPG, PNG, and WEBP are accepted up to 4 MB.</p>
                    <div class="preview-grid profile-photo-grid">
                        <div class="detail-card" style="justify-items:center;text-align:center;">
                            <strong>Live photo preview</strong>
                            @if ($profilePhotoUrl)
                                <img
                                    src="{{ $profilePhotoUrl }}"
                                    alt="{{ $user->name }} selected profile preview"
                                    style="width:132px;height:132px;border-radius:24px;object-fit:cover;border:1px solid rgba(15,31,47,.08);display:block;"
                                    data-profile-photo-preview-image
                                >
                                <div data-profile-photo-preview-placeholder hidden style="width:132px;height:132px;border-radius:24px;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#153c5e,#1f6ca8);color:#fff;font-size:2rem;font-weight:900;letter-spacing:.08em;">{{ $initials }}</div>
                            @else
                                <img
                                    src=""
                                    alt="{{ $user->name }} selected profile preview"
                                    hidden
                                    style="width:132px;height:132px;border-radius:24px;object-fit:cover;border:1px solid rgba(15,31,47,.08);display:block;"
                                    data-profile-photo-preview-image
                                >
                                <div data-profile-photo-preview-placeholder style="width:132px;height:132px;border-radius:24px;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#153c5e,#1f6ca8);color:#fff;font-size:2rem;font-weight:900;letter-spacing:.08em;">{{ $initials }}</div>
                            @endif
                            <span class="incident-code" data-profile-photo-file-name>{{ $profilePhotoUrl ? 'Current profile photo' : 'No photo selected yet' }}</span>
                            <small style="display:block;margin-top:10px;color:#567089;font-size:.82rem;" data-profile-photo-crop-status>{{ $profilePhotoUrl ? 'Current saved photo is shown here.' : 'Auto-crop target: 512 x 512 pixels.' }}</small>
                        </div>
                        <div class="field">
                            <label for="profile_photo">Profile picture</label>
                            <input id="profile_photo" class="input" type="file" name="profile_photo" accept=".jpg,.jpeg,.png,.webp" data-profile-photo-input>
                            <small style="display:block;margin-top:8px;color:#567089;font-size:.82rem;" data-profile-photo-auto-crop>Auto-crop and resize keeps profile photos square and upload-ready.</small>
                            <input type="file" hidden accept="image/*" capture="user" data-profile-photo-camera-input>
                            <input type="hidden" name="remove_profile_photo" value="0">
                            <label style="display:flex;align-items:center;gap:10px;font-size:.92rem;letter-spacing:normal;text-transform:none;">
                                <input type="checkbox" name="remove_profile_photo" value="1" data-profile-photo-remove>
                                Remove current profile picture on save
                            </label>
                            <div class="button-row">
                                <button type="button" class="btn btn-secondary" data-profile-photo-camera-trigger>Capture from camera</button>
                                <button type="button" class="btn btn-secondary" data-profile-photo-reset>Reset preview</button>
                                @if ($profilePhotoUrl)
                                    <button type="button" class="btn btn-danger" data-profile-photo-remove-trigger>Remove current photo</button>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-step-card">
                    <span class="form-step-label">Security</span>
                    <strong>Change password</strong>
                    <p>Leave both fields blank if you do not want to change your password. Minimum length is 6 characters.</p>
                    <div class="button-row">
                        <button type="button" class="btn btn-secondary" data-password-toggle>Show passwords</button>
                    </div>
                    <div class="form-grid">
                        <div class="field">
                            <label for="password">New password</label>
                            <input id="password" class="input" type="password" name="password" autocomplete="new-password" placeholder="Enter new password" data-password-field>
                        </div>
                        <div class="field">
                            <label for="password_confirmation">Confirm new password</label>
                            <input id="password_confirmation" class="input" type="password" name="password_confirmation" autocomplete="new-password" placeholder="Confirm new password" data-password-field>
                        </div>
                        <div class="field">
                            <label>Password reminder</label>
                            <div class="detail-card" style="min-height:50px;">Use a strong password that you can remember and keep private.</div>
                        </div>
                    </div>
                </div>

                <div class="button-row">
                    <button class="btn btn-primary" type="submit">Save Profile Changes</button>
                    <a href="{{ route('settings.index') }}" class="btn btn-secondary">Open Settings</a>
                </div>
            </form>
        </article>
    </div>


@endsection
