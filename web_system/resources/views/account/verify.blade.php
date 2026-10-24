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
                <p>Take one clear selfie to unlock emergency reporting.</p>
            </header>

            <form method="POST" action="{{ route('account.verify.store') }}" enctype="multipart/form-data" class="stack" data-account-verification-form>
                @csrf
                <input type="file" name="verification_selfie" accept="image/jpeg,image/png,image/webp" capture="user" hidden data-account-verification-input>

                <div class="selfie-camera-frame account-face-frame">
                    <div class="verification-camera-placeholder" data-account-verification-placeholder>
                        <svg viewBox="0 0 48 48" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2"><circle cx="24" cy="17" r="7"/><path d="M11 39v-4a13 13 0 0 1 26 0v4M5 14V6h8m22 0h8v8M5 34v8h8m22 0h8v-8"/></svg>
                        <span>Keep your face inside the guide</span>
                    </div>
                    <video data-account-verification-preview autoplay muted playsinline hidden></video>
                    <canvas data-account-verification-canvas hidden></canvas>
                    <img class="detail-media" data-account-verification-image alt="Captured account verification selfie" hidden>
                </div>

                <p class="verification-status" role="status" aria-live="polite" aria-atomic="true" data-account-verification-status>1 of 3 · Open the front camera to begin.</p>

                <div class="button-row selfie-camera-actions account-verification-actions">
                    <button type="button" class="btn btn-primary" data-account-verification-start>Open Front Camera</button>
                    <button type="button" class="btn btn-primary" data-account-verification-capture disabled hidden>Capture Face</button>
                    <button type="submit" class="btn btn-primary" data-account-verification-submit disabled hidden>Verify Account</button>
                </div>
                <button type="button" class="verification-camera-alternative" data-account-verification-fallback>Use Phone Camera instead</button>
            </form>

            <details class="verification-help">
                <summary>Why a selfie? &amp; account details</summary>
                <p>Live facial verification required. Use your real face, good lighting, and one clear selfie. Your selfie is saved as the account verification record to help prevent prank reports.</p>
                <p><strong>{{ $user->name }}</strong><br>{{ $user->email }}</p>
                <p>Emergency reporting stays locked until verification is complete.</p>
            </details>
        </article>
    </section>
@endsection
