# Admin UI responsive update

Local workspace: `C:\laragon\www\CAPSTONE-EMERGENCY-SYSTEM-main`.
Validation recorded on 2026-09-19; this document does not certify every device or OS version.

## Dark mode follow-up

Authenticated pages now default to the browser/device color preference. **Settings > Display mode** offers System, Light Mode, and Dark Mode. A saved manual choice takes precedence; System follows device changes live. The preference synchronizes between tabs and is applied before CSS paints. The ES5 initializer retains the older media-query listener fallback and page-local operation when storage is blocked.

Admin dark colors now cover audit table rows, action buttons, selected navigation, form labels/placeholders, switches, dialogs, and map controls. Accent text uses a brighter color independently from filled red buttons. Map imagery and evidence photos are not inverted.

Validated with axe-core 4.10.3: **17 states each in Chrome 153.0.8010.52 and WebKit 26.5**, covering six pages at 1366px/390px and five dialog/panel states. Both final runs found **zero automated text-contrast violations, horizontal overflow, or unexpected page errors**. Browser/device switching, manual override persistence, and cross-tab synchronization passed. Existing **112 Laravel tests / 1,125 assertions**, production build, and cache refresh passed.

The checker uses the [WCAG text contrast thresholds](https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum.html). Gradients, decorative pseudo-elements, icons, and text over map/media can require manual review; representative screenshots were inspected. This is scoped UI validation, not whole-application accessibility certification or a guarantee for browser extensions that forcibly recolor pages. The transient opacity of the profile-opening animation is allowed to settle before measuring contrast.

Reproduce with `tool/check_admin_dark_mode.cjs` using the same environment-only credentials as the responsive harness. Set `UI_AXE_SOURCE` to a local axe-core script; local screenshots/results are under ignored `tool/runtime/admin-dark/`. The pinned checker was downloaded there for this run, not added to product dependencies. The online deployment still requires the same source and rebuilt assets; it has not been redeployed by this follow-up.

## Changes

- Shared admin layout now uses compact headers, consistent spacing, a collapsible desktop sidebar, and a phone/tablet drawer. Navigation distinguishes Overview, Live Monitoring, and Incident Reports.
- Overview prioritizes operational counts and review reports. Review pagination shows complete cards; audit rows stack on small screens. Maps no longer force neighboring panels to match their height.
- Incident Reports uses responsive cards and an accessible Search & Filters dialog. Destructive report management is a secondary action with confirmation.
- Civilian Accounts has compact filters and wrapping account rows. Admin Profile and System Settings show details and controls without redundant introductory panels; civilian variants are preserved.
- Admin styles are scoped under `body.admin-portal` and built through the separate `resources/css/operations.css` entry. Profile/settings scripts now pass through Vite instead of running untransformed inline JavaScript. Controls retain keyboard focus handling and 44px minimum touch targets where updated.

## Completed local validation

- `php artisan test`: **112 tests, 1,125 assertions passed**.
- `npm run build`: **passed**, including modern and legacy JavaScript assets and the operations stylesheet.
- Chrome **153.0.8010.52**: **90 layout cases passed**, with no detected page overflow or unexpected JavaScript runtime errors.
- Final Chrome touch emulation: **24 additional cases passed** at 1100, 912, 390, and 320px. Forced legacy JavaScript delivery: **12 cases passed** at 390 and 320px. The latter verifies the loader and late initialization on Chrome, not historical-browser support.
- WebKit **26.5**: **48 width/zoom measurements had no overflow or unexpected runtime errors** (six routes at 1366, 1100, 1024, 912, 390, and 320px, plus the 12 zoom-equivalent cases). A final **six-route touch run at 320px passed the complete interaction suite**, including actual report evidence, with zero page errors. This verifies the WebKit engine on Windows, not Safari on a physical iPhone.
- The 78 width cases cover `/admin/dashboard`, `/monitoring`, `/reports`, `/civilian-accounts`, `/profile`, and `/settings` at **1920, 1366, 1280, 1100, 1024, 912, 820, 768, 620, 430, 390, 360, and 320 CSS pixels**.
- The remaining 12 cases cover Overview and Incident Reports at viewport dimensions equivalent to **80%, 125%, 150%, 175%, 200%, and 400%** zoom on a 1366 x 768 viewport. These emulate reflow dimensions; they do not operate the browser's actual zoom control.
- Interaction checks exercised the navigation drawer, desktop collapse, report filters, nested report/map/evidence dialogs, profile editing, civilian account details, alerts, and light/dark display choices. Report dialogs retain keyboard focus and closing a child preserves its parent. Installed-display CSS was exercised at 1100, 912, and 390px without installing a PWA. No report, account, or server-side settings changes were submitted.
- Local services report MySQL ready, web HTTP 200, AI HTTP 200, and Reverb ready. Local `/system/version` after the final build: `6d6b071706f934fc`.

Physical Android/iPhone devices, historical browser releases, and OS versions have not been certified. An earlier WebKit run encountered instability in a synthetic button inserted into a scrolling report body; the final harness tests actual report media, with a header fixture only for reports without media. That real interaction passed. Windows WebKit can also log canceled version requests during rapid document changes as access-control diagnostics: the harness retains these separately during navigation and requires a real HTTP 200 version response. A separate settled-endpoint check and the final touch run had zero such diagnostics.

## Compatibility limits

The Vite legacy configuration targets JavaScript generation/polyfills for Safari/iOS 12+, Chrome 70+, Firefox 78+, and Edge 79+. These build targets do not guarantee that every browser feature, permission, PWA capability, or stylesheet works on those versions. Conventional admin CSS and browser fallbacks improve reach, but they do not establish whole-application support for old devices.

The application also uses Tailwind CSS 4, whose documented core styling baseline is **Chrome 111+, Safari 16.4+, and Firefox 128+**. See the [official Tailwind compatibility documentation](https://tailwindcss.com/docs/compatibility). Use a current browser for the complete styled application. Viewport emulation is evidence of responsive layout behavior, not physical-device or historical-OS certification.

## Repeating checks and deploying

The read-only browser harness is `tool/check_admin_responsive.cjs`. It reads `UI_ADMIN_EMAIL` and `UI_ADMIN_PASSWORD` only from process environment; do not hard-code or commit credentials. `UI_BASE_URL` defaults to `http://127.0.0.1:8000`; optional `UI_ENGINE`, `UI_FORCE_LEGACY`, `UI_TOUCH`, `UI_WIDTHS`, `UI_SKIP_ZOOM`, `UI_BROWSER_PATH`, and `PLAYWRIGHT_MODULE` configure the run. Screenshots/results remain under ignored `tool/runtime/admin-ui/` and can contain account/report information. Map tile downloads and service workers are blocked for isolated layout checks; a disposable DOM image tests the evidence viewer without changing records. Live map delivery, push delivery, and PWA installation were not end-to-end device tests.

The deployment path is **prepared only**. This update has not redeployed or verified `https://stitch-web-demo.onrender.com`. This working copy has no `.git` metadata, so local/deployed commit equality has not been established. Publish the same reviewed source and lockfiles, rebuild the complete asset manifest, clear Laravel caches, and verify public application routes before claiming parity. Follow the [UI rollout section](DEPLOYMENT_RENDER_RAILWAY.md#admin-ui-rollout-2026-09-19).
