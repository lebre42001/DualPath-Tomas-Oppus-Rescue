<footer class="public-footer">
    <div class="public-footer-inner">
        <div class="public-information" aria-label="Website information">
            <details id="help" name="public-information">
                <summary>Help</summary>
                <div class="public-information-copy">
                    <h2>How to report</h2>
                    <ol>
                        <li>Register or log in to your civilian account. Complete account verification if prompted.</li>
                        <li>For a Road Accident, add a scene photo, lock GPS, and enter a short description plus the barangay, road, or nearby landmark.</li>
                        <li>Capture the verification selfie when sending. Wait for confirmation and keep your reference code.</li>
                        <li>Open My Reports to follow the response status.</li>
                    </ol>
                    <h3>Camera, location, or sign-in trouble?</h3>
                    <p>Open the app in your phone's Chrome or Safari browser and allow camera and location access. Google sign-in may be blocked inside an embedded preview browser. You can check device access in Settings after logging in.</p>
                    <h3>Install the app</h3>
                    <p>On Android, choose Download App, open the downloaded APK, and confirm Install. If Android asks, allow Chrome to install this app. On iPhone, use Install App and Safari's Share menu, then Add to Home Screen. The app connects to this online system for reports and updates.</p>
                    <p>A report is received only after submission is confirmed. If you cannot connect during an emergency, contact your local emergency services directly.</p>
                    <a class="public-text-link" href="{{ route('login') }}">Go to Login</a>
                </div>
            </details>
            <details id="about" name="public-information">
                <summary>About</summary>
                <div class="public-information-copy">
                    <h2>About AksyRes</h2>
                    <p>{{ $systemName }}</p>
                    <p>The Civilian Sender app lets you submit an online emergency report, receive a reference code, and follow updates from authorized responders. Road Accident reporting is available; other incident types remain locked.</p>
                    <p>Responders review the emergency description, evidence, and location. Any available AI severity suggestion supports responder review.</p>
                </div>
            </details>
            <details id="developer" name="public-information">
                <summary>Developer</summary>
                <div class="public-information-copy">
                    <h2>Development Team</h2>
                    <p>The AksyRes development team maintains the civilian reporting app and admin response portal.</p>
                    <p>For account or technical assistance, contact your system administrator. Include the page, the error message, and your report reference code when relevant. Do not share your password.</p>
                </div>
            </details>
            <details id="privacy" name="public-information">
                <summary>Privacy</summary>
                <div class="public-information-copy">
                    <h2>Your account and reports</h2>
                    <p>Reporting uses your account information, scene photo, verification selfie, emergency description, and submitted location. Authorized responders use these details to review and respond to your report.</p>
                    <p>A short camera sequence is checked for a visible face and blinks, then discarded. The verified selfie is kept in the private account or report record.</p>
                    <p>Your civilian report history shows your own submissions. Online civilian reports include your GPS reading and the barangay, road, or landmark you provide.</p>
                    <p>Camera and location checks request access on your current device. Running Device Check does not submit a report. Keep your password private and log out on shared devices.</p>
                    <a class="public-text-link" href="{{ route('legal.privacy') }}">Read the full Privacy Policy</a>
                </div>
            </details>
        </div>
        @if ($showLegalLinks ?? true)
            @include('partials.legal-links')
        @endif
        <p class="public-copyright">&copy; {{ now()->year }} AksyRes &middot; Civilian Sender</p>
    </div>
</footer>
