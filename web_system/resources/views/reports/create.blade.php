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
                <h2 class="panel-title">{{ $isCivilian ? 'Field actions' : 'Emergency details' }}</h2>
                <p class="section-copy">{{ $isCivilian ? 'Capture or upload a scene photo, add your location, and describe the emergency. If text AI is unavailable, a responder assesses severity. Photos remain supporting evidence.' : 'Provide a description for severity review. When available, text AI can suggest severity; otherwise a responder assesses it. Photos and video remain supporting evidence.' }}</p>
            </div>
        </div>

        @if ($errors->any() && ! $isCivilian)
            <div class="flash error">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('reports.store') }}" enctype="multipart/form-data" class="stack" data-report-draft-form data-report-role="{{ $isCivilian ? 'civilian' : 'admin' }}">
            @csrf
            <input type="hidden" name="submission_id" value="{{ old('submission_id', $submissionId) }}">

            @if ($isCivilian)
                <div class="civilian-report-mobile-flow">
                    <section class="form-step-card civilian-capture-panel">
                        <span class="form-step-label">Emergency Report</span>
                        <strong>Use these field actions.</strong>
                        <p class="civilian-mobile-hint">Use Capture Photo for real scenes or Upload Photo for downloaded test images. GPS or manual location and a short description are required before sending.</p>

                        <input type="file" name="evidence_photo_capture" accept="image/*" capture="environment" hidden data-capture-photo-input>
                        <input type="file" name="selfie_capture" accept="image/*" capture="user" hidden data-capture-selfie-input>
                        <input id="evidence" type="file" name="evidence" accept=".jpg,.jpeg,.png,.webp" hidden>
                        <input id="selfie" type="file" name="selfie" accept=".jpg,.jpeg,.png,.webp" hidden>
                        <input type="hidden" name="incident_type" value="Road Accident">
                        <input id="latitude" type="hidden" name="latitude" value="{{ old('latitude') }}" data-geo-latitude>
                        <input id="longitude" type="hidden" name="longitude" value="{{ old('longitude') }}" data-geo-longitude>
                        <input type="hidden" name="gps_fallback_used" value="{{ old('gps_fallback_used', '0') }}" data-gps-fallback-used>
                        <button type="button" class="visually-hidden-control" data-geo-fill-button>Get Current Latitude and Longitude</button>

                        <div class="civilian-incident-type-panel">
                            <span class="incident-code">Type of incident</span>
                            <div class="capture-action-grid civilian-incident-type-grid">
                                <button type="button" class="setting-card capture-action-card incident-type-option selected" aria-pressed="true">
                                    <span class="capture-action-icon">01</span>
                                    <strong>Road Accident</strong>
                                    <p>Available for this version.</p>
                                    <span class="tag green">Selected</span>
                                </button>
                                <button type="button" class="setting-card capture-action-card incident-type-option locked" disabled aria-disabled="true">
                                    <span class="capture-action-icon">02</span>
                                    <strong>Fire Incident</strong>
                                    <p>Coming soon.</p>
                                    <span class="tag neutral">Locked</span>
                                </button>
                                <button type="button" class="setting-card capture-action-card incident-type-option locked" disabled aria-disabled="true">
                                    <span class="capture-action-icon">03</span>
                                    <strong>Flood / Landslide</strong>
                                    <p>Coming soon.</p>
                                    <span class="tag neutral">Locked</span>
                                </button>
                                <button type="button" class="setting-card capture-action-card incident-type-option locked" disabled aria-disabled="true">
                                    <span class="capture-action-icon">04</span>
                                    <strong>Medical Emergency</strong>
                                    <p>Coming soon.</p>
                                    <span class="tag neutral">Locked</span>
                                </button>
                            </div>
                        </div>

                        <div class="capture-action-grid civilian-three-button-grid">
                            <button type="button" class="setting-card capture-action-card" data-capture-trigger="photo" data-capture-card="photo" data-capture-state="pending" aria-pressed="false">
                                <span class="capture-action-icon">01</span>
                                <strong>Capture Photo</strong>
                                <p>Scene photo.</p>
                                <span class="tag neutral" data-capture-badge="evidence" aria-live="polite">Photo pending</span>
                            </button>
                            <button type="button" class="setting-card capture-action-card" data-capture-trigger="upload" data-capture-card="upload" data-capture-state="pending" aria-pressed="false">
                                <span class="capture-action-icon">02</span>
                                <strong>Upload Photo</strong>
                                <p>Downloaded test image.</p>
                                <span class="tag neutral" data-capture-badge="upload" aria-live="polite">Upload pending</span>
                            </button>
                            <button type="button" class="setting-card capture-action-card" data-capture-trigger="gps" data-capture-card="gps" data-capture-state="pending" aria-pressed="false">
                                <span class="capture-action-icon">03</span>
                                <strong>Lock GPS</strong>
                                <p>Use location.</p>
                                <span class="tag neutral" data-capture-badge="gps" aria-live="polite">GPS pending</span>
                            </button>
                        </div>

                        <div class="civilian-mobile-status-card">
                            <p data-capture-submit-status>Required first: photo, GPS or manual location, and short description for severity review.</p>
                            <p data-geo-status>GPS not locked yet.</p>
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

                        <div class="civilian-gps-fallback-panel" data-gps-fallback-panel hidden>
                            <span class="tag amber">GPS fallback</span>
                            <strong>Type your barangay, road, or landmark.</strong>
                            <p>If your phone browser blocks GPS or the location signal is weak, type the nearest location so admins still receive a usable report.</p>
                            <div class="field">
                                <label for="location_text">Barangay / road / landmark</label>
                                <input id="location_text" class="input" type="text" name="location_text" value="{{ old('location_text') }}" placeholder="Example: Poblacion near Bontoc Public Market" data-manual-location-input>
                            </div>
                            <p class="section-copy">Best fix: open this link in Android Chrome or iPhone Safari, then allow Location permission.</p>
                        </div>

                        <details class="civilian-compact-details">
                            <summary>Preview selected photo</summary>
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
                                    <span class="tag blue">Identity check</span>
                                    <h3 id="selfieVerificationTitle">Front-camera selfie verification</h3>
                                    <p data-selfie-camera-status>Opening the front camera so admins can verify who sent the report.</p>
                                </div>
                                <div class="selfie-camera-frame">
                                    <video data-selfie-camera-preview autoplay muted playsinline></video>
                                    <canvas data-selfie-camera-canvas hidden></canvas>
                                </div>
                                <div class="button-row selfie-camera-actions">
                                    <button type="button" class="btn btn-primary" data-selfie-camera-capture>Capture Verification Selfie</button>
                                    <button type="button" class="btn btn-secondary" data-selfie-camera-retry hidden>Retry Front Camera</button>
                                    <button type="button" class="btn btn-secondary" data-selfie-camera-fallback>Open Phone Camera Instead</button>
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
                        <span class="form-step-label">Short Description</span>
                        <strong>Describe injuries, hazards, and what happened.</strong>
                        <p>When available, AI uses only this description to propose severity. Otherwise a responder assesses the report. Photos remain evidence for responder review.</p>
                        <div class="field">
                            <label for="description">Short description (required)</label>
                            <textarea id="description" class="textarea" name="description" required placeholder="Example: Motorcycle crash near the barangay road, one injured person, road partially blocked." data-required-description>{{ old('description') }}</textarea>
                        </div>
                    </section>

                    <section class="form-step-card report-submit-card civilian-send-card">
                        <strong>Send when complete.</strong>
                        <p>Tap Send Report after a captured or uploaded scene photo, GPS or manual location, and a short description. The system will request a front-camera verification selfie before final submit.</p>
                        <button class="btn btn-primary" type="submit" data-draft-submit data-report-submit>Send Report</button>
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
