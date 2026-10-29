# Stitch Rescue Web System

Laravel web starter for the Stitch rescue platform.

## Stack

- Laravel 13 (PHP)
- MySQL
- API endpoints for the Flutter mobile app

## Included Starter Areas

- Dashboard summary
- Incident report management pages
- AI severity and transmission views
- Profile and settings views
- JSON API routes under `/api/v1`

## Privacy and app terms

The public Privacy Policy, Terms & Conditions, and App License are available at
`/privacy-policy`, `/terms-and-conditions`, and `/app-license` before and after
sign-in. Registration has separate unchecked terms agreement and privacy-notice
acknowledgment controls, validated on the server. Google sign-in has a matching
pre-OAuth step. Document links also appear in Settings and at sensitive capture
steps; existing ordinary logins do not gain a blocking agreement popup.

Run migrations and rebuild assets when deploying. New web/API accounts record
`legal_documents_version`, `terms_accepted_at`, and `privacy_acknowledged_at`.
API registration requires `terms_accepted`, `privacy_acknowledged`, and
`legal_version`; the latter must match `config/legal.php`. Existing user records
remain null until a real acknowledgment is collected. A privacy acknowledgment
is not consent for every processing purpose.

Configure the actual operator/contact with `LEGAL_OPERATOR_NAME`,
`LEGAL_CONTACT_EMAIL`, and `LEGAL_CONTACT_ADDRESS`. Without a real contact email,
the notice states that a dedicated contact has not yet been published. Do not use
invented contact details or describe these pages as a legal-compliance certificate.
See the root `DOKPLOY_DEPLOYMENT.md` for rollout and verification requirements.

## Admin report alarm

The admin website uses the supplied emergency-alarm MP3 at
`public/sounds/incoming-report-alarm.mp3`. Tap **Enable sound** on each opened or
reloaded admin page; it plays a 1.2-second sample to activate browser audio.
New online and LoRa arrivals play the same sound for up to 10 seconds, without
overlapping or restarting for duplicate delivery. **Stop alarm** silences the
current sound; **Mute sound** disables future sounds on that page. Opening an
alert or marking all alerts seen also stops the current alarm. Initial history
and status changes do not sound. Audio failure leaves visual alerts working.

Keep an admin page open, device audio audible, and the device awake. A closed
website, sleeping device, background suspension, or browser autoplay policy can
prevent sound; this is not an OS-level background alarm or a hardware buzzer.

## Setup

1. Configure your MySQL credentials in `.env`
2. Create the database `stitch_system`
3. Run migrations:

```bash
php artisan migrate
```

4. Start the app:

```bash
php artisan serve
```
