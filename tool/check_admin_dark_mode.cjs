/* Read-only UI checks: credentials from environment, all artifacts in ignored runtime. */
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const engines = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const engineName = process.env.UI_ENGINE || 'chromium';
const base = process.env.UI_BASE_URL || 'http://127.0.0.1:8000';
const baseline = process.env.UI_DARK_BASELINE === '1';
const output = path.join(__dirname, 'runtime/admin-dark');
const axePath = process.env.UI_AXE_SOURCE || path.join(output, 'axe.min.js');
const routes = ['/admin/dashboard', '/monitoring', '/reports', '/civilian-accounts', '/profile', '/settings'];
const results = [], errors = [], navigationDiagnostics = [];
fs.mkdirSync(output, { recursive: true });

(async () => {
    assert(process.env.UI_ADMIN_EMAIL && process.env.UI_ADMIN_PASSWORD, 'Set UI_ADMIN_EMAIL and UI_ADMIN_PASSWORD');
    const browser = await engines[engineName].launch({ headless: true, ...(process.env.UI_BROWSER_PATH ? { executablePath: process.env.UI_BROWSER_PATH } : {}) });
    const report = { engine: engineName, version: browser.version(), baseline, results, errors, navigationDiagnostics };
    try {
        const context = await browser.newContext({ viewport: { width: 1366, height: 900 }, colorScheme: 'dark', serviceWorkers: 'block' });
        if (baseline) await context.addInitScript(() => localStorage.setItem('stitch-theme', 'dark'));
        await context.route('**/*.tile.openstreetmap.org/**', route => route.abort());
        const page = await context.newPage();
        let navigating = true;
        page.on('pageerror', error => {
            if (engineName === 'webkit' && navigating && /\/(system\/version|broadcasting\/auth) due to access control checks\.$/.test(error.message)) navigationDiagnostics.push(error.message);
            else errors.push(error.message);
        });
        page.on('response', response => {
            if (new URL(response.url()).pathname === '/broadcasting/auth' && response.status() >= 400) errors.push(`Broadcast authorization returned HTTP ${response.status()}`);
        });
        await page.goto(base + '/admin/login');
        await page.locator('[name="identity"]').fill(process.env.UI_ADMIN_EMAIL);
        await page.locator('[name="password"]').fill(process.env.UI_ADMIN_PASSWORD);
        await Promise.all([page.waitForURL('**/admin/dashboard'), page.locator('button[type="submit"]').click()]);
        const open = async (route) => {
            navigating = true;
            const version = page.waitForResponse(r => new URL(r.url()).pathname === '/system/version');
            const response = await page.goto(base + route, { waitUntil: 'domcontentloaded' });
            assert.equal(response.status(), 200);
            await page.locator('.sidebar-nav a[aria-current="page"]').waitFor({ state: 'attached' });
            assert.equal((await version).status(), 200);
            await page.waitForLoadState('networkidle');
            navigating = false;
            const dismiss = page.locator('[data-admin-notification-dismiss]');
            if (await dismiss.isVisible()) await dismiss.click();
            if (process.env.UI_DARK_CSS) await page.addStyleTag({ path: process.env.UI_DARK_CSS });
        };
        const audit = async (name, selector = 'body') => {
            // Opening profile dialogs fades from transparent; measure settled text.
            await page.waitForTimeout(350);
            if (!await page.evaluate(() => !!window.axe)) await page.addScriptTag({ path: axePath });
            const result = await page.evaluate(async selector => {
                const scan = await axe.run(document.querySelector(selector), { runOnly: { type: 'rule', values: ['color-contrast'] } });
                const simplify = items => items.flatMap(item => item.nodes.map(node => ({ target: node.target, summary: node.failureSummary || '', checks: node.any.map(check => check.data) })));
                return { violations: simplify(scan.violations), incomplete: simplify(scan.incomplete), passedNodes: scan.passes.reduce((sum, item) => sum + item.nodes.length, 0) };
            }, selector);
            const layout = await page.evaluate(() => ({ width: innerWidth, scrollWidth: document.documentElement.scrollWidth, theme: document.documentElement.dataset.theme }));
            results.push({ name, ...layout, ...result });
            await page.screenshot({ path: path.join(output, `${engineName}-${baseline ? 'baseline-' : ''}${name.replaceAll('/', '_')}-${layout.width}.png`), fullPage: false });
            console.log(`${engineName}: ${name} ${layout.width}px: ${result.violations.length} contrast failures, ${result.incomplete.length} manual checks`);
        };
        for (const width of (process.env.UI_WIDTHS || '1366,390').split(',').map(Number)) {
            await page.setViewportSize({ width, height: 900 });
            for (const route of routes) { await open(route); await audit(route); }
        }
        await open('/reports');
        await page.locator('[data-report-filter-open]').click();
        await audit('report-filters', '[data-report-filter-modal]');
        await page.keyboard.press('Escape');
        const review = page.locator('[data-report-review-label]').first();
        if (await review.count()) {
            await review.click();
            await audit('report-details', '[data-report-detail-modal]');
            await page.keyboard.press('Escape');
        }
        await open('/civilian-accounts');
        const view = page.locator('[data-civilian-account-modal-trigger]').first();
        if (await view.count()) { await view.click(); await audit('account-details', '[data-civilian-account-modal]'); await page.keyboard.press('Escape'); }
        await open('/profile');
        await page.locator('[data-profile-editor-toggle]').first().click();
        await audit('profile-editor', '[data-profile-editor-modal]');
        await page.keyboard.press('Escape');
        await page.locator('[data-alert-center-toggle]').click();
        await audit('alerts', '[data-alert-center-panel]');
        await page.keyboard.press('Escape');
        if (!baseline) {
            await open('/settings');
            assert.equal(await page.locator('html').getAttribute('data-theme'), 'dark', 'Fresh visit follows browser dark preference');
            await page.emulateMedia({ colorScheme: 'light' });
            await page.waitForFunction(() => document.documentElement.dataset.theme === 'light');
            await page.locator('[data-theme-choice="dark"]').click();
            await open('/settings');
            assert.equal(await page.locator('html').getAttribute('data-theme'), 'dark', 'Explicit dark survives reload on light browser');
            await page.locator('[data-theme-choice="system"]').click();
            await page.waitForFunction(() => document.documentElement.dataset.theme === 'light');
            await page.emulateMedia({ colorScheme: 'dark' });
            await page.waitForFunction(() => document.documentElement.dataset.theme === 'dark');
            await page.locator('[data-theme-choice="light"]').click();
            await open('/settings');
            assert.equal(await page.locator('html').getAttribute('data-theme'), 'light', 'Explicit light survives dark browser');
            const sibling = await context.newPage();
            await sibling.goto(base + '/profile', { waitUntil: 'networkidle' });
            await page.locator('[data-theme-choice="dark"]').click();
            await sibling.waitForFunction(() => document.documentElement.dataset.theme === 'dark');
            await sibling.close();
            report.themePreferenceChecksPassed = true;
        }
        report.contrastFailureCount = results.reduce((sum, item) => sum + item.violations.length, 0);
        report.overflowFailureCount = results.filter(item => item.scrollWidth > item.width + 1).length;
        if (report.contrastFailureCount || report.overflowFailureCount || errors.length) process.exitCode = 1;
    } catch (error) { report.error = error.message; process.exitCode = 1; }
    finally {
        fs.writeFileSync(path.join(output, `${engineName}-${baseline ? 'baseline' : 'results'}.json`), JSON.stringify(report, null, 2));
        console.log(JSON.stringify({ cases: results.length, contrastFailures: report.contrastFailureCount, overflow: report.overflowFailureCount, errors, error: report.error }));
        await browser.close();
    }
})();
