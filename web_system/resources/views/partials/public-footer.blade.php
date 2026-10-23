<footer class="public-footer">
    <div class="public-footer-inner">
        <div class="public-information" aria-label="Website information">
            <details id="help" name="public-information">
                <summary>Help</summary>
                <div class="public-information-copy">
                    <h2>How to report</h2>
                    <ol>
                        <li>Register or log in to your civilian account. Complete account verification if prompted.</li>
                        <li>For a Road Accident, add a scene photo, GPS or a nearby landmark, and a short description.</li>
                        <li>Capture the verification selfie when sending. Wait for confirmation and keep your reference code.</li>
                        <li>Open My Reports to follow the response status.</li>
                    </ol>
                    <h3>Camera, location, or sign-in trouble?</h3>
                    <p>Open the app in your phone's Chrome or Safari browser and allow camera and location access. Google sign-in may be blocked inside an embedded preview browser. You can check device access in Settings after logging in.</p>
                    <h3>Install the app</h3>
                    <p>Choose Install App, then follow the browser instructions. On iPhone, use Safari's Share menu and Add to Home Screen.</p>
                    <p>A report is received only after submission is confirmed. If you cannot connect during an emergency, contact your local emergency services directly.</p>
                    <a class="public-text-link" href="{{ route('login') }}">Go to Login</a>
                </div>
            </details>
            <details id="about" name="public-information">
                <summary>About</summary>
                <div class="public-information-copy">
                    <h2>About Tomas Oppus Rescue</h2>
                    <p>{{ $systemName }}</p>
                    <p>The Civilian Sender app lets you submit an online emergency report, receive a reference code, and follow updates from authorized responders. Road Accident reporting is available; other incident types remain locked.</p>
                    <p>Responders review the emergency description, evidence, and location. Any available AI severity suggestion supports responder review.</p>
                </div>
            </details>
            <details id="developer" name="public-information">
                <summary>Developer</summary>
                <div class="public-information-copy">
                    <h2>Development Team</h2>
                    <p>The Tomas Oppus Rescue development team maintains the civilian reporting app and admin response portal.</p>
                    <p>For account or technical assistance, contact your system administrator. Include the page, the error message, and your report reference code when relevant. Do not share your password.</p>
                </div>
            </details>
            <details id="privacy" name="public-information">
                <summary>Privacy</summary>
                <div class="public-information-copy">
                    <h2>Your account and reports</h2>
                    <p>Reporting uses your account information, scene photo, verification selfie, emergency description, and submitted location. Authorized responders use these details to review and respond to your report.</p>
                    <p>Your civilian report history shows your own submissions. The location attached to a report is the GPS reading or manual landmark you provide when reporting.</p>
                    <p>Camera and location checks request access on your current device. Running Device Check does not submit a report. Keep your password private and log out on shared devices.</p>
                </div>
            </details>
        </div>
        <p class="public-copyright">&copy; {{ now()->year }} Tomas Oppus Rescue &middot; Civilian Sender</p>
    </div>
</footer>
