<section>
    <h2>At a glance</h2>
    <p>AksyRes is an emergency-reporting prototype. This notice covers its website, installable PWA, Android launcher, and reports received from ESP32-LoRa nodes. It explains how information is collected, used, shared, and retained, and how you can raise a concern.</p>
    <p>Only your incident description is sent to the severity classifier. Face/blink verification is a separate camera-processing step. Authorized personnel review reports and remain responsible for response decisions.</p>
</section>
<section>
    <h2>Information collected and why</h2>
    <ul>
        <li><strong>Account and access:</strong> name, email, phone/contact details, profile information, password hash, verification status, login IP address/browser details, and session/presence information support sign-in, accountability, access controls, and account administration. If you choose Google sign-in, Google provides your verified email, account identifier, name, and available profile image.</li>
        <li><strong>Online incidents:</strong> description, current browser/device coordinates, written barangay/road/landmark, scene evidence, verification selfie, submission time, reference code, response messages, and review history support incident assessment and coordination. Civilian reporting requires both coordinates and a written location.</li>
        <li><strong>Camera verification:</strong> a short live face/blink sequence is checked by the private verification service. Those frames are processed in memory rather than saved as a video by the application. The verified selfie, challenge/proof hashes, and verification timestamps are retained. This is a basic live-face check, not government identity matching.</li>
        <li><strong>LoRa incidents:</strong> short message, originating node/message ID, registered installation location, route/hop information, reception indicators, available battery measurements, and acknowledgment history support delivery and monitoring. The registered node position is not a live measurement of the victim. The radio report does not carry a scene photo or selfie.</li>
        <li><strong>Preferences and records:</strong> app settings, locally saved drafts, document acknowledgment version/timestamps, and administrative/security audit records support usability and traceability.</li>
    </ul>
    <p>Use only information relevant to the emergency. Avoid unnecessary details about bystanders. Browser camera/location permission controls access on your device; it does not by itself authorize unrelated uses of the resulting data.</p>
</section>
<section>
    <h2>How processing works</h2>
    <p>Information is used to provide the requested account/report service, verify submissions, secure the service, and support human incident review. Depending on the circumstances, the operator must establish an applicable basis under the Philippine Data Privacy Act, including the additional conditions for sensitive information. This notice and its acknowledgment are not substitutes for that assessment or for specific consent where required.</p>
    <p>The experimental text model analyzes description wording and may return Minor, Serious, Critical, or Needs review, with uncalibrated class scores and phrase-based assistance. These are suggestions, not diagnoses or survival probabilities. Administrators confirm or correct assessments; automated output does not independently dispatch assistance. LoRa intake does not automatically run this classifier.</p>
    <p>Reports are not automatically added to training datasets by the reporting workflow. Separate research, publication, or training use needs its own appropriate authorization and safeguards; accepting account terms does not grant that permission.</p>
</section>
<section>
    <h2>Who can receive information</h2>
    <p>Civilian accounts can access their own reports. Authorized responders and administrators can access records needed for their assigned functions, including evidence, reviews, exports, and account administration. Hosting/database services process the data needed to operate this installation; the private text and face-verification services process their respective inputs.</p>
    <p>Google processes its sign-in flow when you choose it. Loading external map tiles reveals network/request information to the configured map provider; route requests send origin/destination coordinates to the configured routing service. Do not assume every service runs on your phone. Necessary lawful disclosure to emergency personnel or public authorities depends on the circumstances; this prototype does not automatically connect every report to official dispatch.</p>
</section>
<section>
    <h2>Storage, local drafts, and retention</h2>
    <p>Account/report records and uploaded evidence are stored on the configured application/database servers. Protected routes control access to retained evidence. Your browser stores session cookies and may store settings, cached app files, and report drafts locally. A local draft is not a received emergency report. Clear local drafts and sign out before handing a shared device to someone else.</p>
    <p>Account/report data is retained until an authorized deletion or another applicable retention decision; the current prototype does not impose a fixed automatic expiry on these records. Administrative audit records may remain after a report is deleted. Verification proofs expire quickly, but proof expiry does not delete the retained selfie or report. Backup copies, where maintained, may follow a separate schedule.</p>
    <p>Ask the operator for the applicable retention schedule and deletion options. A request is reviewed against operational needs, other people's rights, and applicable obligations; immediate deletion of every record is not guaranteed.</p>
</section>
<section>
    <h2>Choices, rights, and security limits</h2>
    <p>You may request information, access, correction, or deletion/blocking where applicable; object to processing or withdraw consent where consent is the basis; and raise concerns with the operator or the <a href="https://privacy.gov.ph/data-subject-rights/" target="_blank" rel="noopener noreferrer">National Privacy Commission</a>. Identity verification may be needed before acting on a request. Withdrawal does not automatically invalidate earlier lawful processing.</p>
    <p>You can decline device permissions or stop using the app. Required camera/location steps will then be unavailable for online civilian submission. If an emergency cannot be submitted, use an available local emergency contact or nearby assistance rather than waiting for this app.</p>
    <p>Access controls and transport safeguards reduce risk but cannot promise absolute confidentiality. Field-node Wi-Fi access is open; compact radio messages are not represented as end-to-end encrypted private conversations. Avoid unnecessary personal details in a LoRa message. Keep account credentials private.</p>
</section>
<section>
    <h2>Changes to this notice</h2>
    <p>The version and update date above identify this notice. Registration records the version acknowledged and the time; existing accounts are not marked as having accepted a new version automatically. Material changes must be communicated appropriately, with separate consent where required.</p>
</section>
