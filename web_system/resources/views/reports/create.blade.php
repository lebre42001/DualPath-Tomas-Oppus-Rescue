@extends('layouts.app')

@php
    $isCivilian = auth()->user()?->isCivilian() ?? false;
    $evidenceUploadError = $errors->first('evidence');
@endphp

@section('title', $isCivilian ? 'Send Emergency Report' : 'Create Report')
@section('page_label', $isCivilian ? 'Send Report' : 'Manual Report Entry')
@section('page_heading', $isCivilian ? 'Civilian Emergency Report' : 'Create an Incident from the Web Dashboard')
@section('page_subheading', $isCivilian ? 'Capture or upload a scene photo, lock GPS, describe the emergency for severity review, then verify your selfie when sending.' : 'Use this admin-side form for manual incident entry while preserving the standard Laravel incident workflow.')
@section('hide_topbar', $isCivilian ? 'true' : 'false')

@section('hero')
    @unless ($isCivilian)
        <section class="hero-card">
            <div class="hero-grid">
                <div class="hero-copy">
                    <p class="eyebrow">Manual Report Entry</p>
                    <h2>Create an incident from the web dashboard.</h2>
                    <p>
                        Describe the emergency for a proposed severity assessment. Include the location and attach photos or video
                        as evidence for responder review.
                    </p>
                    <div class="hero-actions">
                        <a href="#report-form" class="btn btn-primary">Open Incident Form</a>
                        <a href="{{ route('reports.index') }}" class="btn btn-secondary">Back To Feed</a>
                        <a href="{{ route('settings.readiness') }}" class="btn btn-secondary">Check Device Readiness</a>
                    </div>
                </div>
                <div class="hero-metrics">
                    <article class="metric-card"><span>Workflow</span><strong>Manual</strong><p>Direct dashboard submission for admin-side entry.</p></article>
                    <article class="metric-card"><span>Severity review</span><strong>Description</strong><p>Text AI may suggest severity when available. Responders review the report.</p></article>
                    <article class="metric-card"><span>Transport</span><strong>Online / LoRa</strong><p>Choose the delivery channel that matches the scenario.</p></article>
                    <article class="metric-card"><span>Evidence</span><strong>Optional</strong><p>Photo or video uploads remain available in the same form.</p></article>
                </div>
            </div>
        </section>
    @endunless
@endsection

@section('content')
    <section id="report-form" class="panel stack" data-report-form-root>
        <div class="panel-head">
            <div class="panel-heading">
                <p class="panel-kicker">{{ $isCivilian ? 'Emergency Form' : 'Incident Form' }}</p>
                <h2 class="panel-title">{{ $isCivilian ? 'Report emergency' : 'Emergency details' }}</h2>
                @unless ($isCivilian)
                    <p class="section-copy">Provide a description for severity review. When available, text AI can suggest severity; otherwise a responder assesses it. Photos and video remain supporting evidence.</p>
                @endunless
            </div>
        </div>

        @if ($errors->any() && ! $isCivilian)
            <div class="flash error">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('reports.store') }}" enctype="multipart/form-data" class="stack" data-report-draft-form data-report-role="{{ $isCivilian ? 'civilian' : 'admin' }}" data-liveness-challenge-url="{{ route('face-liveness.challenge') }}" data-liveness-verify-url="{{ route('face-liveness.verify') }}">
            @csrf
            <input type="hidden" name="submission_id" value="{{ old('submission_id', $submissionId) }}">

            @if ($isCivilian)
                <p class="legal-small">Your location, scene photo, verification selfie, and description are shared with authorized reviewers. Only the description is used for severity AI. <a href="{{ route('legal.privacy') }}">Privacy Policy</a>.</p>
                <div class="civilian-report-mobile-flow">
                    <section class="form-step-card civilian-capture-panel">
                        <details class="civilian-report-type" data-incident-picker>
                            <summary><span>Type of incident</span><strong>Road Accident</strong></summary>
                            <div class="civilian-incident-options" role="group" aria-label="Type of incident">
                                <label class="civilian-incident-option">
                                    <input type="radio" name="incident_type" value="Road Accident" checked data-incident-selected>
                                    <span>Road Accident</span><small>Selected</small>
                                </label>
                                @foreach (['Fire Incident', 'Flood / Landslide', 'Medical Emergency'] as $incidentType)
                                    <label class="civilian-incident-option is-locked">
                                        <input type="radio" name="incident_type" value="{{ $incidentType }}" disabled>
                                        <span>{{ $incidentType }}</span><small>Locked</small>
                                    </label>
                                @endforeach
                                <p>Other incident types are coming soon.</p>
                            </div>
                        </details>
                        <span class="form-step-label">1. Scene photo &amp; location</span>

                        <input type="file" name="evidence_photo_capture" accept="image/*" capture="environment" hidden data-capture-photo-input>
                        <input type="file" name="selfie_capture" accept="image/jpeg" hidden data-capture-selfie-input>
                        <input type="hidden" name="liveness_proof" value="" data-liveness-proof>
                        <input id="evidence" type="file" name="evidence" accept=".jpg,.jpeg,.png,.webp" hidden>
                        <input id="selfie" type="file" name="selfie" accept=".jpg,.jpeg,.png,.webp" hidden>
                        <input id="latitude" type="hidden" name="latitude" value="" data-geo-latitude>
                        <input id="longitude" type="hidden" name="longitude" value="" data-geo-longitude>
                        <button type="button" class="visually-hidden-control" data-geo-fill-button>Get Current Latitude and Longitude</button>

                        <div class="capture-action-grid civilian-three-button-grid">
                            <button type="button" class="setting-card capture-action-card" data-capture-trigger="photo" data-capture-card="photo" data-capture-state="pending" aria-pressed="false">
                                <strong>Capture Photo</strong>
                                <span class="tag neutral" data-capture-badge="evidence" aria-live="polite">Photo pending</span>
                            </button>
                            <button type="button" class="setting-card capture-action-card" data-capture-trigger="upload" data-capture-card="upload" data-capture-state="pending" aria-pressed="false">
                                <strong>Upload Photo</strong>
                                <span class="tag neutral" data-capture-badge="upload" aria-live="polite">Upload pending</span>
                            </button>
                            <button type="button" class="setting-card capture-action-card" data-capture-trigger="gps" data-capture-card="gps" data-capture-state="pending" aria-pressed="false">
                                <strong>Lock GPS</strong>
                                <span class="tag neutral" data-capture-badge="gps" aria-live="polite">GPS pending</span>
                            </button>
                        </div>

                        <div class="civilian-mobile-status-card">
                            <p data-geo-status role="status">Enable device location and tap Lock GPS. Location access is required to send.</p>
                            <div class="visually-hidden-control" aria-hidden="true">
                                <span data-requirement-status="photo">Still required</span>
                                <span data-requirement-status="selfie">Selfie opens on send</span>
                                <span data-requirement-status="gps">Still required</span>
                                <span data-requirement-status="description">Still required</span>
                                <span data-draft-queue-count>0 queued</span>
                                <span data-draft-autosave-state>Autosave ready</span>
                                <span data-draft-status>Draft ready.</span>
                            </div>
                        </div>

                        <div class="civilian-gps-fallback-panel">
                            <div class="field">
                                <label for="location_text">Barangay / road / landmark (required)</label>
                                <input id="location_text" class="input" type="text" name="location_text" value="{{ old('location_text') }}" placeholder="Example: Poblacion near Bontoc Public Market" maxlength="180" required aria-describedby="location-text-help" data-manual-location-input>
                                <small id="location-text-help">Enter a barangay, road, or nearby landmark to help rescuers find you. GPS is also required.</small>
                            </div>
                        </div>

                        <details class="civilian-compact-details">
                            <summary>Photo preview &amp; reporting help</summary>
                            <div class="civilian-report-help">
                                <p>Use a real scene photo. GPS, a barangay/road/landmark, and a short description are required.</p>
                                <p>A responder reviews your report. Any available AI suggestion uses only your description; photos remain evidence.</p>
                                <p>Road Accident is available for this version. Fire Incident, Flood / Landslide, and Medical Emergency: Coming soon.</p>
                                <p>If device access is blocked, open in Android Chrome or iPhone Safari and allow camera and location.</p>
                            </div>
                            <div class="preview-grid">
                                <article class="preview-card">
                                    <strong>Scene photo preview</strong>
                                    <img class="preview-thumb" data-evidence-preview-image alt="Evidence preview" hidden>
                                    <p data-evidence-preview-name>No photo selected yet.</p>
                                </article>
                                <article class="preview-card">
                                    <strong>Verification selfie preview</strong>
                                    <img class="preview-thumb" data-selfie-preview-image alt="Selfie preview" hidden>
                                    <p data-selfie-preview-name>No verification selfie selected yet.</p>
                                </article>
                            </div>
                        </details>

                        <div class="selfie-camera-modal" data-selfie-camera-modal role="dialog" aria-modal="true" aria-labelledby="selfieVerificationTitle" hidden>
                            <div class="selfie-camera-sheet">
                                <div class="selfie-camera-copy">
                                    <span class="tag blue">Live face check</span>
                                    <h3 id="selfieVerificationTitle">Front-camera selfie verification</h3>
                                    <p data-selfie-camera-status>Show one face and blink slowly when asked. A live check is required before sending.</p>
                                </div>
                                <div class="selfie-camera-frame" data-face-guide>
                                    <video data-selfie-camera-preview autoplay muted playsinline></video>
                                    <canvas data-selfie-camera-canvas hidden></canvas>
                                    <span class="live-face-outline" hidden aria-hidden="true"></span>
                                    <span class="live-face-label" data-face-guide-label hidden aria-hidden="true"></span>
                                </div>
                                <div class="button-row selfie-camera-actions">
                                    <button type="button" class="btn btn-primary" data-selfie-camera-capture>Check Face &amp; Blink</button>
                                    <button type="button" class="btn btn-secondary" data-selfie-camera-retry hidden>Retry Front Camera</button>
                                    <button type="button" class="btn btn-secondary" data-selfie-camera-fallback>Camera help</button>
                                    <button type="button" class="btn btn-danger" data-selfie-camera-cancel>Cancel</button>
                                </div>
                            </div>
                        </div>

                        <div class="detail-card inline-warning-card" data-evidence-preview-warning-card hidden data-warning-tone="amber">
                            <div class="tag-row">
                                <span class="tag amber" data-evidence-preview-warning-tag>Preview check</span>
                                <span class="tag neutral">Photo review</span>
                            </div>
                            <strong data-evidence-preview-warning-title>Review the selected evidence photo.</strong>
                            <p data-evidence-preview-warning-body>The selected file may not look like a real accident or emergency scene photo. Replace it now if it seems unrelated.</p>
                            <ul class="section-copy warning-reason-list" data-evidence-preview-warning-list></ul>
                            <div class="button-row warning-actions">
                                <button type="button" class="btn btn-primary" data-evidence-replace-trigger>Replace Photo</button>
                                <button type="button" class="btn btn-secondary" data-warning-dismiss-trigger>Keep Current File</button>
                            </div>
                        </div>
                    </section>

                    <section class="form-step-card civilian-description-panel">
                        <span class="form-step-label">2. Short Description</span>
                        <div class="field">
                            <label for="description">Short description (required)</label>
                            <textarea id="description" class="textarea" name="description" required placeholder="Example: Motorcycle crash near the barangay road, one injured person, road partially blocked." data-required-description>{{ old('description') }}</textarea>
                        </div>
                    </section>

                    <section class="form-step-card report-submit-card civilian-send-card">
                        <p data-capture-submit-status role="status" aria-live="polite">Add a scene photo, location, and short description.</p>
                        <button class="btn btn-primary" type="submit" data-draft-submit data-report-submit>Send Report</button>
                        <p class="civilian-selfie-note">A verification selfie opens before the report is sent.</p>
                    </section>
                </div>
            @else
                <div class="report-form-shell">
                    <div class="report-form-main">
                        <div class="form-step-card">
                            <span class="form-step-label">Step 1 - Describe</span>
                            <strong>Emergency details</strong>
                            <p>Fill out the operational fields below to submit a new incident from the web dashboard.</p>
                            <div class="form-grid">
                                <div class="field">
                                    <label for="incident_type">Incident type</label>
                                    <input id="incident_type" class="input" type="text" name="incident_type" value="{{ old('incident_type', 'General Emergency') }}" placeholder="Vehicular Accident, Landslide, Fire, Injury" required>
                                </div>
                                <div class="field">
                                    <label for="transmission_type">Transmission</label>
                                    <select id="transmission_type" name="transmission_type">
                                        <option value="online" @selected(old('transmission_type', 'online') === 'online')>Online</option>
                                        <option value="lora" @selected(old('transmission_type') === 'lora')>LoRa</option>
                                    </select>
                                </div>
                                <div class="field">
                                    <label for="severity">Severity override</label>
                                    <select id="severity" name="severity">
                                        <option value="">Description assessment (AI when available)</option>
                                        @foreach (['Minor', 'Serious', 'Fatal'] as $severity)
                                            <option value="{{ $severity }}" @selected(old('severity') === $severity)>{{ $severity }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <div class="field">
                                <label for="description">Description</label>
                                <textarea id="description" class="textarea" name="description" required placeholder="Describe the incident, injuries, hazards, road condition, or what admins should expect." data-required-description>{{ old('description') }}</textarea>
                                <p>When available, AI uses only this description to propose severity. Otherwise a responder assesses the report.</p>
                            </div>
                        </div>

                        <div class="form-step-card">
                            <span class="form-step-label">Step 2 - Locate</span>
                            <strong>Location and GPS</strong>
                            <p>Use your browser location to quickly populate the GPS coordinates for this manual report.</p>
                            <div class="field">
                                <label for="location_text">Location description</label>
                                <input id="location_text" class="input" type="text" name="location_text" value="{{ old('location_text') }}" placeholder="Barangay, road, landmark, or GPS text" required>
                            </div>
                            <div class="form-grid">
                                <div class="field">
                                    <label for="latitude">Latitude</label>
                                    <input id="latitude" class="input" type="text" name="latitude" value="{{ old('latitude') }}" placeholder="17.089400" data-geo-latitude>
                                </div>
                                <div class="field">
                                    <label for="longitude">Longitude</label>
                                    <input id="longitude" class="input" type="text" name="longitude" value="{{ old('longitude') }}" placeholder="120.977000" data-geo-longitude>
                                </div>
                            </div>
                            <div class="button-row">
                                <button type="button" class="btn btn-secondary" data-geo-fill-button>Get Current Latitude and Longitude</button>
                            </div>
                            <p class="section-copy" data-geo-status>Waiting for manual entry or browser GPS request.</p>
                        </div>

                        <div class="form-step-card">
                            <span class="form-step-label">Step 3 - Save Backup</span>
                            <strong>Offline draft queue</strong>
                            <p>Keep a local backup of the current manual report so command staff do not lose encoded details during connectivity interruptions.</p>
                            <div class="tag-row">
                                <span class="tag blue" data-draft-queue-count>0 queued</span>
                                <span class="tag neutral" data-draft-autosave-state>Autosave ready</span>
                            </div>
                            <div class="button-row">
                                <button type="button" class="btn btn-secondary" data-draft-save>Save Draft Now</button>
                                <button type="button" class="btn btn-secondary" data-draft-restore>Restore Latest Draft</button>
                                <button type="button" class="btn btn-danger" data-draft-clear>Clear Draft Queue</button>
                            </div>
                            <p class="section-copy" data-draft-status>Current form changes will stay on this device as a local draft.</p>
                        </div>
                    </div>

                    <div class="report-form-side">
                        <div class="form-step-card">
                            <strong>Evidence upload</strong>
                            <p>Attach photos or video as supporting evidence for responders. {{ \App\Models\IncidentReport::manualOnlyMode() ? 'A responder assesses severity from the emergency description.' : 'Any AI severity suggestion uses the description only.' }}</p>
                            <div class="field">
                                <label for="evidence">Photo or video file</label>
                                <input id="evidence" class="input" type="file" name="evidence" accept=".jpg,.jpeg,.png,.webp,.mp4,.mov,.avi,.3gp">
                            </div>
                            <div class="field">
                                <label for="selfie">Verification selfie (optional)</label>
                                <input id="selfie" class="input" type="file" name="selfie" accept=".jpg,.jpeg,.png,.webp">
                            </div>
                        </div>

                        <div class="form-step-card">
                            <strong>Dispatch notes</strong>
                            <p>Use the description assessment, or choose a manual severity after responder review has confirmed the level.</p>
                            <div class="tag-row">
                                <span class="tag blue">Online</span>
                                <span class="tag green">LoRa</span>
                                <span class="tag neutral">GPS Ready</span>
                            </div>
                            <div class="button-row">
                                <a href="{{ route('settings.readiness') }}" class="btn btn-secondary">Open Device Readiness Check</a>
                            </div>
                        </div>

                        <div class="form-step-card report-submit-card">
                            <strong>Command action</strong>
                            <p>Submit the incident now or return to the main incident feed.</p>
                            <div class="button-row">
                                <button class="btn btn-primary" type="submit" data-draft-submit data-report-submit>Submit Report</button>
                                <a href="{{ route('reports.index') }}" class="btn btn-secondary">Cancel</a>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        </form>
    </section>
@endsection
