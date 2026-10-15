/* Read-only browser checks. Credentials stay in process environment; artifacts
   stay in ignored tool/runtime. No report/account/settings writes are submitted. */
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium, webkit } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const base = process.env.UI_BASE_URL || 'http://127.0.0.1:8000';
const output = path.resolve(__dirname, 'runtime/admin-ui');
const engineName = process.env.UI_ENGINE || 'chromium';
const forcedLegacy = process.env.UI_FORCE_LEGACY === '1';
const touchMode = process.env.UI_TOUCH === '1';
const reportPrefix = `${engineName}${forcedLegacy ? '-legacy' : ''}${touchMode ? '-touch' : ''}`;
const routes = ['/admin/dashboard', '/monitoring', '/reports', '/civilian-accounts', '/profile', '/settings'];
const widths = process.env.UI_WIDTHS ? process.env.UI_WIDTHS.split(',').map(Number) : [1920,1366,1280,1100,1024,912,820,768,620,430,390,360,320];
const zooms = process.env.UI_SKIP_ZOOM === '1' ? [] : [0.8,1.25,1.5,1.75,2,4];
const results = [];
const errors = [];
const navigationDiagnostics = [];
fs.mkdirSync(output, { recursive: true });

(async () => {
    assert(process.env.UI_ADMIN_EMAIL && process.env.UI_ADMIN_PASSWORD, 'Set UI_ADMIN_EMAIL and UI_ADMIN_PASSWORD');
    const engine = engineName === 'webkit' ? webkit : chromium;
    const browser = await engine.launch({ headless: true, ...(process.env.UI_BROWSER_PATH ? { executablePath: process.env.UI_BROWSER_PATH } : {}) });
    try {
        const context = await browser.newContext({ viewport: { width: 1366, height: 768 }, serviceWorkers: 'block', ...(touchMode ? { isMobile: true, hasTouch: true, deviceScaleFactor: 2 } : {}) });
        if (forcedLegacy) {
            await context.addInitScript(() => Object.defineProperty(window, '__vite_is_modern_browser', { get: () => false, set: () => {} }));
            const manifest = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../web_system/public/build/manifest.json')));
            const modernFile = manifest['resources/js/app.js'].file;
            await context.route(`**/build/${modernFile}`, route => route.fulfill({ contentType: 'application/javascript', body: 'throw new Error("Intentional modern-entry rejection for legacy loader verification");' }));
        }
        const page = await context.newPage();
        const activate = async (locator) => touchMode ? locator.tap() : locator.click();
        let navigationInProgress = true;
        page.on('pageerror', error => {
            if (error.message === 'Intentional modern-entry rejection for legacy loader verification') return;
            // Windows WebKit reports canceled same-origin version fetches during document
            // replacement as access-control pageerrors. Preserve these diagnostics separately;
            // every tested page still must complete its real version request with HTTP 200.
            if (engineName === 'webkit' && navigationInProgress && error.message.endsWith('/system/version due to access control checks.')) {
                navigationDiagnostics.push({ message: error.message, url: page.url() });
            } else errors.push(error.message);
        });
        // Tile downloads are unnecessary for layout measurements; don't collect external resources.
        await context.route('**/*.tile.openstreetmap.org/**', route => route.abort());
        await page.goto(base + '/admin/login', { waitUntil: 'domcontentloaded' });
        await page.locator('input[name="identity"]').fill(process.env.UI_ADMIN_EMAIL);
        await page.locator('input[name="password"]').fill(process.env.UI_ADMIN_PASSWORD);
        await Promise.all([page.waitForURL('**/admin/dashboard'), page.locator('button[type="submit"]').click()]);
        const waitForApplication = () => page.locator('.sidebar-nav a[aria-current="page"]').waitFor({ state: 'attached' });
        const openPage = async (route) => {
            navigationInProgress = true;
            const versionResponse = page.waitForResponse(response => new URL(response.url()).pathname === '/system/version' && response.request().method() === 'GET');
            const response = await page.goto(base + route, { waitUntil: 'domcontentloaded' });
            // Legacy assets may initialize after DOMContentLoaded; wait for sidebar initialization.
            await waitForApplication();
            const version = await versionResponse;
            assert.equal(version.status(), 200, 'Live version endpoint must remain available');
            await version.finished();
            await page.waitForLoadState('networkidle');
            navigationInProgress = false;
            return response;
        };
        await waitForApplication();
        const dismissNotifications = async () => {
            const dismiss = page.locator('[data-admin-notification-dismiss]');
            if (await dismiss.isVisible()) await dismiss.click();
        };
        await page.waitForTimeout(forcedLegacy ? 1200 : 250);
        await dismissNotifications();
        for (const width of widths) {
            await page.setViewportSize({ width, height: width >= 1024 ? 768 : 844 });
            for (const route of routes) {
                const response = await openPage(route);
                assert.equal(response.status(), 200, route);
                await page.waitForTimeout(forcedLegacy ? 300 : 100);
                await dismissNotifications();
                const layout = await page.evaluate(() => {
                    const root = document.documentElement;
                    const header = document.querySelector('.topbar');
                    const visibleOverflow = [...document.querySelectorAll('main *')].filter(el => {
                        if (el.closest('.leaflet-container, [hidden], .incident-feed-expanded, .civilian-account-modal-source, .civilian-account-modal, .report-detail-modal')) return false;
                        // Mobile table headings remain available to screen readers but are clipped visually.
                        const tableHead = el.closest('.command-audit-table thead');
                        if (tableHead && getComputedStyle(tableHead).clip !== 'auto') return false;
                        const b = el.getBoundingClientRect(), style = getComputedStyle(el);
                        return b.width && b.height && style.visibility !== 'hidden' && (b.right > innerWidth + 2 || b.left < -2);
                    }).slice(0, 8).map(el => el.className || el.tagName);
                    return { width: innerWidth, scrollWidth: root.scrollWidth, headerHeight: Math.round(header?.getBoundingClientRect().height || 0), visibleOverflow };
                });
                results.push({ route, ...layout });
                if ([1366,390].includes(width)) {
                    await page.waitForTimeout(500); // Let Leaflet's initial fit animation settle.
                    await page.screenshot({ path: path.join(output, `${reportPrefix}-${route.replaceAll('/','_')}-${width}.png`), fullPage: false });
                }
            }
            console.log(`${engineName}: checked ${width}px`);
        }
        // Browser zoom changes the CSS viewport: test equivalent narrow and short layouts.
        for (const zoom of zooms) {
            const width = Math.round(1366 / zoom), height = Math.round(768 / zoom);
            await page.setViewportSize({ width, height });
            for (const route of ['/admin/dashboard','/reports']) {
                await openPage(route);
                await page.waitForTimeout(80);
                results.push({ route, zoomEquivalent: zoom, ...await page.evaluate(() => ({ width: innerWidth, height: innerHeight, scrollWidth: document.documentElement.scrollWidth })) });
            }
        }
        // Exercise controls without submitting changes.
        await page.setViewportSize({ width: 390, height: 844 });
        await openPage('/reports');
        await activate(page.locator('[data-sidebar-toggle]'));
        await page.locator('[data-sidebar-close]').waitFor({ state: 'visible' });
        assert.equal(await page.locator('[data-sidebar-toggle]').getAttribute('aria-expanded'), 'true');
        await page.keyboard.press('Escape');
        assert.equal(await page.locator('[data-sidebar-toggle]').getAttribute('aria-expanded'), 'false');
        await page.locator('[data-sidebar-toggle]').click();
        await page.setViewportSize({ width: 1366, height: 768 });
        await page.waitForTimeout(250);
        assert.equal(await page.locator('[data-sidebar-backdrop]').isVisible(), false);
        await page.locator('[data-sidebar-collapse-toggle]').click();
        assert.equal(await page.locator('body').evaluate(el => el.classList.contains('sidebar-collapsed')), true);
        await page.locator('[data-sidebar-collapse-toggle]').click();
        await page.setViewportSize({ width: 320, height: 568 });
        await activate(page.locator('[data-report-filter-open]'));
        const filter = page.locator('[data-report-filter-modal]');
        await filter.waitFor({ state: 'visible' });
        const filterBox = await filter.locator('.report-filter-modal-shell').boundingBox();
        assert(filterBox.x >= 0 && filterBox.x + filterBox.width <= 321, 'Filter dialog must fit phone width');
        await page.keyboard.press('Escape');
        assert.equal(await filter.isVisible(), false);
        const review = page.locator('[data-report-review-label]').first();
        if (await review.count()) {
            await activate(review);
            const detail = page.locator('[data-report-detail-modal]');
            await detail.waitFor({ state: 'visible' });
            assert(await detail.evaluate(el => el.contains(document.activeElement)), 'Report must receive keyboard focus');
            const mapTrigger = detail.locator('[data-report-map-trigger]').first();
            if (await mapTrigger.count()) {
                await activate(mapTrigger);
                await page.locator('[data-report-map-modal]').waitFor({ state: 'visible' });
                await page.keyboard.press('Escape');
                assert.equal(await page.locator('[data-report-map-modal]').isVisible(), false);
                assert.equal(await detail.isVisible(), true, 'Closing the map must keep its report open');
                assert.equal(await page.evaluate(() => document.body.style.overflow), 'hidden');
            }
            // Use actual report media when available. A header fixture covers empty
            // reports without shifting the focused map control inside a scrolling body.
            if (!await detail.locator('[data-media-viewer-trigger]').count()) {
                await detail.evaluate(el => {
                    const trigger = document.createElement('button');
                    trigger.type = 'button';
                    trigger.textContent = 'Preview';
                    trigger.dataset.mediaViewerTrigger = '';
                    trigger.dataset.mediaType = 'photo';
                    trigger.dataset.mediaSrc = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
                    el.querySelector('.report-detail-modal-head').append(trigger);
                });
            }
            await activate(detail.locator('[data-media-viewer-trigger]').first());
            const viewer = page.locator('[data-media-viewer]');
            await viewer.waitFor({ state: 'visible' });
            assert.equal(await viewer.evaluate(el => getComputedStyle(el).position), 'fixed');
            assert(await viewer.evaluate(el => el.contains(document.activeElement)), 'Viewer must receive focus');
            await page.keyboard.press('Escape');
            assert.equal(await viewer.isVisible(), false);
            assert.equal(await detail.isVisible(), true, 'Closing evidence must keep its report open');
            assert.equal(await page.evaluate(() => document.body.style.overflow), 'hidden');
            await page.keyboard.press('Escape');
            assert.equal(await detail.isVisible(), false);
            assert.equal(await review.evaluate(el => document.activeElement === el), true, 'Closing a report restores focus');
        }
        await openPage('/profile');
        await page.locator('[data-profile-editor-toggle]').first().click();
        await page.locator('[data-profile-editor-modal]').waitFor({ state: 'visible' });
        await page.keyboard.press('Escape');
        assert.equal(await page.locator('[data-profile-editor-modal]').isVisible(), false);
        await activate(page.locator('[data-alert-center-toggle]'));
        const alerts = page.locator('[data-alert-center-panel]');
        await alerts.waitFor({ state: 'visible' });
        const alertBox = await alerts.boundingBox();
        assert(alertBox.x >= 0 && alertBox.x + alertBox.width <= 321, 'Alerts must fit phone width');
        await page.keyboard.press('Escape');
        assert.equal(await alerts.isVisible(), false);
        await openPage('/civilian-accounts');
        const accountView = page.locator('[data-civilian-account-modal-trigger]').first();
        if (await accountView.count()) {
            await activate(accountView);
            const account = page.locator('[data-civilian-account-modal]');
            await account.waitFor({ state: 'visible' });
            const accountBox = await account.locator('.civilian-account-modal-shell').boundingBox();
            assert(accountBox.x >= 0 && accountBox.x + accountBox.width <= 321, 'Account dialog must fit phone width');
            await page.keyboard.press('Escape');
            assert.equal(await account.isVisible(), false);
        }
        await openPage('/settings');
        await activate(page.locator('[data-theme-choice="dark"]'));
        assert.equal(await page.locator('html').getAttribute('data-theme'), 'dark');
        await page.screenshot({ path: path.join(output, `${reportPrefix}-settings-dark-320.png`) });
        await activate(page.locator('[data-theme-choice="light"]'));
        // Exercise the installed-display CSS class without claiming an actual PWA installation.
        for (const width of [1100,912,390]) {
            await page.setViewportSize({ width, height: 844 });
            await page.evaluate(() => document.body.classList.add('is-standalone'));
            await page.waitForFunction(expectedWidth => {
                const sidebar = document.querySelector('[data-sidebar]');
                return innerWidth === expectedWidth && getComputedStyle(sidebar).position === (expectedWidth <= 1024 ? 'fixed' : 'sticky');
            }, width);
            // WebKit can finish the click before the new visibility transition is painted.
            // Allow responsive resize handlers to run, then wait for the intended visible state.
            await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
            if (width <= 1024) await activate(page.locator('[data-sidebar-toggle]'));
            const navigationLabel = page.locator('.sidebar-nav a span').first();
            await navigationLabel.waitFor({ state: 'visible' });
            assert(await navigationLabel.isVisible(), `Installed display must retain readable navigation at ${width}px`);
            if (width <= 1024) await page.keyboard.press('Escape');
            await page.evaluate(() => document.body.classList.remove('is-standalone'));
        }
        const failures = results.filter(r => r.scrollWidth > r.width + 1 || r.visibleOverflow?.length);
        const report = { engine: engineName, forcedLegacy, touchMode, browserVersion: browser.version(), measuredCases: results.length, controlChecksPassed: true,
            note: 'Viewport and zoom-equivalent emulation; not a physical phone or historical browser certification.', navigationDiagnostics, errors, failures, results };
        fs.writeFileSync(path.join(output, `${reportPrefix}-results.json`), JSON.stringify(report, null, 2));
        console.log(JSON.stringify({ engine: engineName, version: browser.version(), cases: results.length, failures, errors, navigationDiagnostics: navigationDiagnostics.length }, null, 2));
        if (failures.length || errors.length) process.exitCode = 1;
    } finally { await browser.close(); }
})().catch(error => {
    fs.writeFileSync(path.join(output, `${reportPrefix}-partial.json`), JSON.stringify({ results, errors, navigationDiagnostics, error: error.message }, null, 2));
    console.error(error.message);
    process.exitCode = 1;
});
