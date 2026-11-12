/* Real Blade/JS, isolated in-memory identity and GET-only fixture server.
   Never forwards requests or creates an operational report. */
const fs = require('node:fs'), path = require('node:path'), http = require('node:http');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '..'), publicRoot = path.join(root, 'web_system/public');
const output = path.join(__dirname, 'runtime/incident-categories');
fs.mkdirSync(output, { recursive: true });
const html = execFileSync(process.env.UI_PHP || 'php', [path.join(__dirname, 'fixtures/render_incident_categories.php')], { cwd: root, encoding: 'utf8' });
let origin, attemptedReportPosts = 0;
const server = http.createServer((req, res) => {
    const url = new URL(req.url, origin || 'http://localhost');
    if (req.method !== 'GET') { if (url.pathname === '/reports') attemptedReportPosts++; res.writeHead(405).end(); return; }
    if (url.pathname.startsWith('/reports/submissions/')) { res.setHeader('Content-Type', 'application/json'); res.end('{"received":false}'); return; }
    if (url.pathname === '/reports/create' || url.pathname === '/') {
        res.setHeader('Content-Type', 'text/html'); res.end(html.replaceAll('http://incident.fixture', origin)); return;
    }
    const file = path.resolve(publicRoot, '.' + url.pathname);
    if (!file.startsWith(publicRoot + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) { res.writeHead(404).end(); return; }
    res.setHeader('Content-Type', file.endsWith('.css') ? 'text/css' : file.endsWith('.js') ? 'application/javascript' : 'application/octet-stream');
    res.end(fs.readFileSync(file));
});
(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    origin = `http://127.0.0.1:${server.address().port}`;
    const browser = await chromium.launch({ headless: true, ...(process.env.UI_BROWSER_PATH ? { executablePath: process.env.UI_BROWSER_PATH } : {}) });
    try {
        const page = await browser.newPage({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
        await page.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
        await page.goto(origin + '/reports/create');
        const options = page.locator('[data-incident-option]');
        assert.equal(await options.count(), 6);
        assert.equal(await options.locator('xpath=self::*[@disabled]').count(), 0);
        const openPicker = async () => {
            if (!(await page.locator('[data-incident-picker]').evaluate(element => element.open))) {
                await page.locator('[data-incident-picker] summary').click();
            }
        };
        for (const category of ['Road Accident', 'Fire Incident', 'Flood / Landslide', 'Medical Emergency', 'Security Incident', 'Other']) {
            await openPicker();
            await page.getByRole('radio', { name: category, exact: false }).check();
            await page.waitForFunction(value => document.querySelector('[data-incident-summary]').textContent === value, category);
        }
        await openPicker();
        await page.getByRole('radio', { name: 'Fire Incident', exact: false }).check();
        await page.locator('[name="description"]').fill('Fictional draft category preservation check.');
        await page.locator('[name="location_text"]').fill('Fixture location');
        const submissionId = await page.locator('[name="submission_id"]').inputValue();
        await page.locator('[data-civilian-draft-save]').click();
        await page.waitForFunction(() => document.querySelector('[data-civilian-draft-feedback]')?.textContent.includes('saved'));
        await page.goto(origin + '/reports/create?draft=' + submissionId);
        await page.waitForFunction(() => document.querySelector('[data-incident-summary]')?.textContent === 'Fire Incident');
        assert.equal(await page.locator('[name="incident_type"]:checked').inputValue(), 'Fire Incident');
        for (const width of [320, 390, 1024]) {
            await page.setViewportSize({ width, height: 844 });
            await openPicker();
            const fits = await page.locator('[data-incident-picker]').evaluate(element => element.getBoundingClientRect().right <= innerWidth + 1);
            assert(fits, 'Incident picker overflows at ' + width);
            await page.screenshot({ path: path.join(output, 'picker-' + width + '.png'), fullPage: true });
            await page.locator('[data-incident-picker] summary').click();
        }
        assert.equal(attemptedReportPosts, 0);
        process.stdout.write(JSON.stringify({ categories: 6, category_switching: 'passed', draft_category_restore: 'passed', widths: [320, 390, 1024], real_reports_created: 0 }) + '\n');
    } finally { await browser.close(); server.close(); }
})().catch(error => { process.stderr.write(error.stack + '\n'); server.close(); process.exitCode = 1; });
