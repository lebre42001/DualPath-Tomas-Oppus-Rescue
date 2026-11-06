// Real rendered civilian Home/CSS/tutorial/dialog/sidebar code, synthetic in-memory
// accounts, isolated HTTP server. Never accesses live auth or submits a report.
const fs = require('node:fs'), path = require('node:path'), http = require('node:http'), assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const repo = path.resolve(__dirname, '..'), web = path.join(repo, 'web_system'), output = path.join(__dirname, 'runtime/civilian-tutorial');
fs.mkdirSync(output, { recursive: true });
const source = fs.readFileSync(path.join(web, 'resources/js/stitch-dashboard.js'), 'utf8');
const sidebar = source.slice(source.indexOf('function initializeSidebar() {'), source.indexOf('function initializeMaps() {'));
const rendered = new Map(); let origin, posts = 0;
const server = http.createServer((req, res) => {
    const url = new URL(req.url, origin || 'http://localhost');
    if (req.method !== 'GET') { posts++; res.writeHead(405).end(); return; }
    if (url.pathname.startsWith('/modules/')) { res.setHeader('Content-Type', 'application/javascript'); res.end(fs.readFileSync(path.join(web, 'resources/js', path.basename(url.pathname)))); return; }
    if (url.pathname.startsWith('/build/assets/')) {
        res.setHeader('Content-Type', url.pathname.endsWith('.css') ? 'text/css' : 'application/javascript');
        res.end(fs.readFileSync(path.join(web, 'public/build/assets', path.basename(url.pathname)))); return;
    }
    if (url.pathname.startsWith('/icons/')) {
        const file = path.join(web, 'public/icons', path.basename(url.pathname));
        if (fs.existsSync(file)) { res.setHeader('Content-Type', 'image/png'); res.end(fs.readFileSync(file)); } else res.writeHead(404).end(); return;
    }
    const owner = /^[1-4]$/.test(url.searchParams.get('owner')) ? url.searchParams.get('owner') : '1';
    const verified = url.searchParams.get('verified') === '1' ? '1' : '0', key = `${owner}:${verified}`;
    if (!rendered.has(key)) {
        const html = execFileSync(process.env.QA_PHP_PATH || 'php', [path.join(__dirname, 'fixtures/render_civilian_tutorial.php'), owner, verified], { cwd: web, encoding: 'utf8', maxBuffer: 4 * 1024 * 1024 });
        rendered.set(key, html.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '').replace(/<link\b[^>]*rel="modulepreload"[^>]*>/gi, ''));
    }
    const html = rendered.get(key).replaceAll('http://tutorial.fixture', origin).replaceAll('http:\\/\\/tutorial.fixture', origin).replace('</body>', `<script>function invalidateVisibleMaps(){};${sidebar};initializeSidebar();</script><script type="module">import '/modules/shell-dialogs.js';import '/modules/civilian-tutorial.js';</script></body>`);
    res.setHeader('Content-Type', 'text/html; charset=utf-8'); res.end(html);
});
(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve)); origin = `http://127.0.0.1:${server.address().port}`;
    let browser;
    try {
        browser = await chromium.launch({ headless: true, ...(process.env.UI_BROWSER_PATH ? { executablePath: process.env.UI_BROWSER_PATH } : {}) });
        for (const [width, height] of [[320, 568], [390, 844], [1366, 768], [1920, 1080]]) {
            const context = await browser.newContext({ viewport: { width, height }, isMobile: width < 600, hasTouch: width < 600 });
            await context.addInitScript(() => {
                window.tourPermissionCalls = 0;
                if (navigator.mediaDevices) navigator.mediaDevices.getUserMedia = async () => { window.tourPermissionCalls++; throw new Error('No camera during tutorial'); };
                if (navigator.geolocation) navigator.geolocation.getCurrentPosition = () => { window.tourPermissionCalls++; };
            });
            const page = await context.newPage(), errors = []; page.on('pageerror', error => errors.push(error.message));
            const tour = page.locator('[data-civilian-tutorial]'), next = tour.locator('[data-tutorial-next]');
            const ready = async () => {
                try { await page.waitForFunction(() => { const t = document.querySelector('[data-civilian-tutorial]'); return t && !t.hidden && !t.querySelector('[data-tutorial-next]').disabled; }, null, { timeout: 8000 }); }
                catch (error) { console.error(JSON.stringify({ errors, state: await page.evaluate(() => { const t = document.querySelector('[data-civilian-tutorial]'); return t ? { hidden: t.hidden, dataset: t.dataset, nextDisabled: t.querySelector('[data-tutorial-next]').disabled, title: t.querySelector('[data-tutorial-title]').textContent } : { missing: true }; }) })); throw error; }
            };
            await page.goto(origin+'/dashboard'); await ready();
            assert.equal(await tour.locator('[data-tutorial-title]').textContent(), 'Welcome to AksyRes');
            await tour.getByRole('button', { name: 'Skip', exact: true }).click(); await tour.waitFor({ state: 'hidden' });
            await page.reload(); await page.waitForTimeout(500); assert(await tour.isHidden());
            await page.getByRole('button', { name: 'Quick tutorial', exact: true }).click(); await ready();
            for (let step = 0; step < 16; step++) {
                await ready();
                const geometry = await tour.locator('[data-tutorial-callout]').boundingBox();
                if (step > 0) assert.equal(await tour.getAttribute('data-pointing'), 'true', `${width}px: step ${step + 1} points to a visible object`);
                assert(geometry.x >= 10 && geometry.y >= 10 && geometry.x + geometry.width <= width - 10 && geometry.y + geometry.height <= height - 10, `${width}px: step ${step + 1} stays on screen`);
                assert(await page.evaluate(() => document.querySelector('[data-civilian-tutorial]').contains(document.activeElement)));
                if (step === 3) {
                    assert.equal(await tour.locator('[data-tutorial-demo]').isVisible(), true);
                    await page.screenshot({ path: path.join(output, `photo-step-${width}.png`) });
                    await tour.getByRole('button', { name: 'Back', exact: true }).click(); await ready();
                    assert.equal(await tour.locator('[data-tutorial-title]').textContent(), 'Report / Verify Account');
                    await next.click(); await ready();
                }
                if (step === 8) await page.screenshot({ path: path.join(output, `draft-tab-${width}.png`) });
                if (step === 15) { assert.equal(await next.textContent(), 'Done'); assert(await tour.locator('[data-tutorial-verify]').isVisible()); }
                await next.click();
            }
            await tour.waitFor({ state: 'hidden' });
            assert.equal(await page.evaluate(() => JSON.parse(localStorage.getItem('aksyres-civilian-tutorial:v1:1')).status), 'done');
            assert.equal(await page.evaluate(() => document.body.classList.contains('sidebar-open')), false);
            assert.equal(await page.evaluate(() => window.tourPermissionCalls), 0); assert.equal(posts, 0);
            await page.reload(); await page.waitForTimeout(500); assert(await tour.isHidden());
            await page.goto(origin+'/dashboard?owner=2&verified=1'); await page.waitForTimeout(500); assert(await tour.isHidden());
            await page.getByRole('button', { name: 'Quick tutorial', exact: true }).click(); await ready();
            for (let i = 0; i < 6; i++) { await page.keyboard.press('Tab'); assert(await page.evaluate(() => document.querySelector('[data-civilian-tutorial]').contains(document.activeElement))); }
            await page.keyboard.press('Escape'); await tour.waitFor({ state: 'hidden' });
            await page.goto(origin+'/dashboard?owner=3'); await ready(); await next.click(); await ready(); await next.click(); await ready(); await next.click(); await ready();
            await page.reload(); await ready(); assert.equal(await tour.locator('[data-tutorial-title]').textContent(), '1. Add a scene photo');
            await page.setViewportSize({ width: height, height: width }); await ready();
            await tour.getByRole('button', { name: 'Skip', exact: true }).click();
            await page.goto(origin+'/dashboard?owner=4'); await ready();
            await page.evaluate(() => localStorage.setItem('aksyres-civilian-tutorial:v1:4', JSON.stringify({ status: 'active', step: 15 })));
            await page.reload(); await ready(); await tour.locator('[data-tutorial-verify]').click(); await page.waitForURL('**/account/verify');
            assert.equal(await page.evaluate(() => JSON.parse(localStorage.getItem('aksyres-civilian-tutorial:v1:4')).status), 'done');
            assert.deepEqual(errors, []);
            console.log(`${width}px: 16 guided steps, safe previews, real nav/sidebar spotlight, Next/Back/Skip/Done, replay/resume/account isolation, keyboard/Escape, resize and verification handoff passed; no report POST or permission request`);
            await context.close();
        }
    } finally { await browser?.close(); await new Promise(resolve => server.close(resolve)); }
})().catch(error => { console.error(error); process.exitCode = 1; });
