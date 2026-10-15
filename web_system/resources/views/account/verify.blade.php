@extends('layouts.app')

@section('title', 'Verify Account')
@section('page_label', 'Account Verification')
@section('page_heading', 'Verify Civilian Account')
@section('page_subheading', 'Complete live facial verification once to unlock emergency reporting.')

@section('content')
    <section class="civilian-shell">
        <article class="panel stack account-verification-panel" data-account-verification-root>
            <div class="panel-head">
                <div class="panel-heading">
                    <p class="panel-kicker">Anti-prank protection</p>
                    <h2 class="panel-title">Live facial verification required.</h2>
                    <p class="section-copy">Capture your face using the front camera. Once verified, this account can send emergency reports.</p>
                </div>
                <span class="tag amber">Locked until verified</span>
            </div>

            <div class="preview-grid">
                <div class="detail-card">
                    <strong>Verification rules</strong>
                    <p>Use your real face, good lighting, and one clear selfie. This is saved as the account verification record to help avoid prank reports.</p>
                </div>
                <div class="detail-card">
                    <strong>Account</strong>
                    <p>{{ $user->name }}<br>{{ $user->email }}</p>
                </div>
            </div>

            <form method="POST" action="{{ route('account.verify.store') }}" enctype="multipart/form-data" class="stack" data-account-verification-form>
                @csrf
                <input type="file" name="verification_selfie" accept="image/*" capture="user" hidden data-account-verification-input>

                <div class="selfie-camera-frame account-face-frame">
                    <video data-account-verification-preview autoplay muted playsinline></video>
                    <canvas data-account-verification-canvas hidden></canvas>
                    <img class="detail-media" data-account-verification-image alt="Captured account verification selfie" hidden>
                </div>

                <div class="civilian-mobile-status-card">
                    <p data-account-verification-status>Open the front camera and capture your face to unlock Send Report.</p>
                </div>

                <div class="button-row selfie-camera-actions account-verification-actions">
                    <button type="button" class="btn btn-secondary" data-account-verification-start>Open Front Camera</button>
                    <button type="button" class="btn btn-primary" data-account-verification-capture disabled>Capture Face</button>
                    <button type="button" class="btn btn-secondary" data-account-verification-fallback>Use Phone Camera</button>
                    <button type="submit" class="btn btn-primary" data-account-verification-submit disabled>Verify Account</button>
                </div>
            </form>
        </article>
    </section>
@endsection
