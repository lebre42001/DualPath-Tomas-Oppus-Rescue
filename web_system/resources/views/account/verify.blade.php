@extends('layouts.app')

@section('title', 'Verify Account')
@section('page_label', 'Account Verification')
@section('page_heading', 'Verify Civilian Account')
@section('page_subheading', 'Complete live facial verification once to unlock emergency reporting.')
@section('hide_topbar', 'true')

@section('content')
    <section class="civilian-shell">
        <article class="panel stack account-verification-panel" data-account-verification-root>
            <header class="verification-heading">
                <p class="panel-kicker">Account verification</p>
                <h1>Verify your account</h1>
                <p>Complete a face and blink check to unlock emergency reporting.</p>
                <p class="legal-small">A short camera sequence is sent for face/blink verification. The verified selfie is saved; the sequence is not retained as a video. <a href="{{ route('legal.privacy') }}">Read the Privacy Policy</a>.</p>
            </header>

            <form method="POST" action="{{ route('account.verify.store') }}" enctype="multipart/form-data" class="stack" data-account-verification-form data-liveness-challenge-url="{{ route('face-liveness.challenge') }}" data-liveness-verify-url="{{ route('face-liveness.verify') }}">
                @csrf
                <input type="hidden" name="liveness_proof" value="" data-liveness-proof>
                <input type="file" name="verification_selfie" accept="image/jpeg,image/png,image/webp" hidden data-account-verification-input>

                <div class="selfie-camera-frame account-face-frame" data-face-guide>
                    <div class="verification-camera-placeholder" data-account-verification-placeholder>
                        <svg viewBox="0 0 48 48" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2"><circle cx="24" cy="17" r="7"/><path d="M11 39v-4a13 13 0 0 1 26 0v4M5 14V6h8m22 0h8v8M5 34v8h8m22 0h8v-8"/></svg>
                        <span>Keep your face inside the guide</span>
                    </div>
                    <video data-account-verification-preview autoplay muted playsinline hidden></video>
                    <canvas data-account-verification-canvas hidden></canvas>
                    <img class="detail-media" data-account-verification-image alt="Captured account verification selfie" hidden>
                    <span class="live-face-outline" hidden aria-hidden="true"></span>
                    <span class="live-face-label" data-face-guide-label hidden aria-hidden="true"></span>
                </div>

                <p class="verification-status" role="status" aria-live="polite" aria-atomic="true" data-account-verification-status>1 of 3 · Open the front camera to begin.</p>

                <div class="button-row selfie-camera-actions account-verification-actions">
                    <button type="button" class="btn btn-primary" data-account-verification-start>Open Front Camera</button>
                    <button type="button" class="btn btn-primary" data-account-verification-capture disabled hidden>Check Face &amp; Blink</button>
                    <button type="submit" class="btn btn-primary" data-account-verification-submit disabled hidden>Verify Account</button>
                </div>
                <button type="button" class="verification-camera-alternative" data-account-verification-fallback>Camera help</button>
            </form>

            <details class="verification-help">
                <summary>Why a selfie? &amp; account details</summary>
                <p>Live facial verification required. Look at the camera until the guide turns green. Capture starts automatically; blink slowly when asked. After the server approves the blink check, your verification is saved automatically. Preview detection stays on your device. The blink sequence is discarded; only the verified selfie is saved.</p>
                <p><strong>{{ $user->name }}</strong><br>{{ $user->email }}</p>
                <p>Emergency reporting stays locked until verification is complete.</p>
            </details>
        </article>
    </section>
@endsection
