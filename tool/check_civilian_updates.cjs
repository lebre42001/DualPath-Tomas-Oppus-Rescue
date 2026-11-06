// Isolated browser fixture: real widget/modules/CSS, mocked private read-only feeds.
// Never signs in to the live app or submits emergency reports.
const fs = require('node:fs'), path = require('node:path'), http = require('node:http');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const { execFileSync } = require('node:child_process');
const web = path.resolve(__dirname, '../web_system');
const releaseNotesHtml = execFileSync(process.env.QA_PHP_PATH || 'php', ['-r', "require 'vendor/autoload.php'; $app = require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo view('partials.civilian-release-notes')->render();"], { cwd: web, encoding: 'utf8' });
const output = path.join(__dirname, 'runtime/civilian-updates');
fs.mkdirSync(output, { recursive: true });
const manifest = JSON.parse(fs.readFileSync(path.join(web, 'public/build/manifest.json')));
const css = ['resources/css/app.css', 'resources/css/operations.css'].map(key => `<link rel="stylesheet" href="/build/${manifest[key].file}">`).join('');
const partial = fs.readFileSync(path.join(web, 'resources/views/partials/civilian-updates.blade.php'), 'utf8')
    .replace("@include('partials.civilian-release-notes')", () => releaseNotesHtml)
    .replace(/{{ \$authUser->id }}/g, '1').replace(/{{ \$pwaAssetVersion }}/g, '1111111111111111')
    .replace(/{{ route\('([^']+)'\) }}/g, (_, name) => ({ 'civilian.system-updates': '/feed', 'civilian.system-updates.read': '/read', 'civilian.app-events': '/events', 'reports.connection': '/connection', 'civilian.system-log': '/log' }[name]));
const server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    if (url.pathname.startsWith('/modules/')) {
        const file = path.join(web, 'resources/js', path.basename(url.pathname));
        res.setHeader('Content-Type', 'application/javascript'); res.end(fs.readFileSync(file)); return;
    }
    if (url.pathname.startsWith('/build/')) {
        const file = path.join(web, 'public/build/assets', path.basename(url.pathname));
        res.setHeader('Content-Type', 'text/css'); res.end(fs.readFileSync(file)); return;
    }
    if (['/feed', '/events', '/connection', '/read'].includes(url.pathname)) {
        res.setHeader('Content-Type', 'application/json');
        res.end(JSON.stringify(url.pathname === '/feed' ? { version: '2222222222222222', unread_count: 2, release_notes_html: releaseNotesHtml, notifications: [{ id: 1, title: 'Report acknowledged', message: 'Your report was acknowledged.', occurred_at: new Date().toISOString() }] } : { saved: true, available: true })); return;
    }
    res.setHeader('Content-Type', 'text/html; charset=utf-8');
    res.end(`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="fixture">${css}</head><body class="civilian-portal"><main style="padding:24px"><h1>Civilian updates fixture</h1><a href="/drafts">Draft Reports</a> <a href="/log">System Log</a></main><nav class="mobile-quick-nav">${['Home', 'Report', 'Drafts', 'Profile', 'Settings', 'System Log'].map(name => `<a href="#"><strong>${name}</strong></a>`).join('')}</nav>${partial}<script type="module">import '/modules/shell-dialogs.js';import '/modules/report-connection.js';import '/modules/civilian-system.js';</script></body></html>`);
});
(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const origin = `http://127.0.0.1:${server.address().port}`;
    let browser;
    try {
        browser = await chromium.launch({ headless: true, ...(process.env.UI_BROWSER_PATH ? { executablePath: process.env.UI_BROWSER_PATH } : {}) });
        for (const [name, width, height, touch] of [['phone', 390, 844, true], ['laptop', 1366, 768, false], ['desktop', 1920, 1080, false]]) {
            const context = await browser.newContext({ viewport: { width, height }, isMobile: touch, hasTouch: touch });
            const page = await context.newPage(), errors = [];
            page.on('pageerror', error => errors.push(error.message));
            await page.goto(origin);
            const toggle = page.locator('[data-civilian-updates-toggle]'), modal = page.locator('[data-civilian-updates-modal]');
            await page.waitForFunction(() => document.querySelector('[data-civilian-update-count]').textContent === '2');
            const before = await toggle.boundingBox();
            if (touch) {
                const cdp = await context.newCDPSession(page);
                const send = (type, x, y) => cdp.send('Input.dispatchTouchEvent', { type, touchPoints: type === 'touchEnd' ? [] : [{ x, y }] });
                await send('touchStart', before.x + 20, before.y + 20);
                await send('touchMove', 110, 230); await send('touchEnd');
            } else {
                await page.mouse.move(before.x + 20, before.y + 20); await page.mouse.down();
                await page.mouse.move(110, 230, { steps: 10 }); await page.mouse.up();
            }
            assert(await modal.isHidden(), `${name}: dragging must not open the modal`);
            const moved = await toggle.boundingBox();
            assert(Math.abs(moved.x - 90) < 2 && Math.abs(moved.y - 210) < 2, `${name}: drag moves badge`);
            await toggle.click(); await modal.waitFor({ state: 'visible' });
            await modal.locator('[data-civilian-release-notes] > article > strong').waitFor();
            await page.screenshot({ path: path.join(output, `${name}-release-notes.png`) });
            const earlier = modal.locator('details[data-release-id="2026.11.07.3"]');
            await earlier.locator('summary').click();
            await earlier.getByText(/Browse ten reports per page/).waitFor();
            const previous = await page.locator('[data-report-connection-result]').textContent();
            await page.getByRole('button', { name: 'Check again', exact: true }).click();
            await page.waitForFunction(value => document.querySelector('[data-report-connection-result]').textContent !== value && document.querySelector('[data-report-connection]').dataset.connectionState === 'available', previous);
            assert(await earlier.evaluate(node => node.open), 'Checking must keep earlier notes expanded');
            await modal.getByRole('button', { name: 'Mark shown updates as read', exact: true }).click();
            assert(await earlier.evaluate(node => node.open));
            assert(await modal.locator('[data-civilian-release-notes] > article > strong').isVisible());
            assert(await page.getByRole('button', { name: 'Update app', exact: true }).isVisible());
            await page.screenshot({ path: path.join(output, `${name}-modal.png`) });
            await page.keyboard.press('Escape'); assert(await modal.isHidden());
            await page.reload();
            assert(Math.abs((await toggle.boundingBox()).x - moved.x) < 2, `${name}: position survives reload`);
            await page.setViewportSize({ width: 320, height: 568 });
            const small = await toggle.boundingBox();
            assert(small.x >= 12 && small.x + small.width <= 308 && small.y >= 12 && small.y + small.height <= 568);
            await toggle.click(); await modal.waitFor({ state: 'visible' });
            await page.keyboard.press('Escape');
            await toggle.focus(); await page.keyboard.press('Home');
            assert.equal(await toggle.evaluate(button => button.style.left), '');
            assert.deepEqual(errors, []);
            console.log(`${name}: drag, modal/check/update, dated readable notes/history, expanded notes after refresh/read, saved position, resize and keyboard reset passed`);
            await context.close();
        }
    } finally { await browser?.close(); await new Promise(resolve => server.close(resolve)); }
})().catch(error => { console.error(error); process.exitCode = 1; });
