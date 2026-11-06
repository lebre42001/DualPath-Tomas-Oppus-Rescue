// Browser QA uses real civilian partials/modules/CSS and isolated mock feeds.
// It never logs in to the live app or submits emergency reports.
const fs = require('node:fs'), path = require('node:path'), http = require('node:http');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const web = path.resolve(__dirname, '../web_system'), output = path.join(__dirname, 'runtime/civilian-report-library');
fs.mkdirSync(output, { recursive: true });
const manifest = JSON.parse(fs.readFileSync(path.join(web, 'public/build/manifest.json')));
const css = ['resources/css/app.css', 'resources/css/operations.css'].map(key => `<link rel="stylesheet" href="/build/${manifest[key].file}">`).join('');
const partial = name => fs.readFileSync(path.join(web, 'resources/views', name), 'utf8')
    .replace(/{{ (?:\$authUser->id|auth\(\)->id\(\)) }}/g, '1')
    .replace(/{{ route\('([^']+)'(?:, '__submission__')?\) }}/g, (_, route) => ({ 'reports.civilian-collection': '/collection', 'reports.create': '/create', 'reports.submission': '/receipt/__submission__' }[route]));
const reports = Array.from({ length: 23 }, (_, index) => ({ id: index + 1, code: `INC-${String(index + 1).padStart(2, '0')}`, type: 'Road Accident', location: index % 2 ? 'Market_2 50%' : 'Road near school', status: index % 2 ? 'resolved' : 'received', statusLabel: index % 2 ? 'Resolved' : 'Pending', submittedAt: `2026-11-07T00:${String(index).padStart(2, '0')}:00Z`, updatedAt: `2026-11-07T01:${String(index).padStart(2, '0')}:00Z`, detailsUrl: `/details/${index + 1}` }));
const server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    if (url.pathname.startsWith('/modules/')) { res.setHeader('Content-Type', 'application/javascript'); res.end(fs.readFileSync(path.join(web, 'resources/js', path.basename(url.pathname)))); return; }
    if (url.pathname.startsWith('/build/')) { res.setHeader('Content-Type', 'text/css'); res.end(fs.readFileSync(path.join(web, 'public/build/assets', path.basename(url.pathname)))); return; }
    if (url.pathname === '/collection') {
        const params = url.searchParams, search = (params.get('search') || '').toLowerCase();
        let matches = reports.filter(r => `${r.code} ${r.location}`.toLowerCase().includes(search) && (!params.get('status') || r.status === params.get('status')) && (!params.get('from') || params.get('from') <= '2026-11-07') && (!params.get('to') || params.get('to') >= '2026-11-07'));
        matches = [...matches].sort((a, b) => params.get('sort') === 'oldest' ? a.id - b.id : b.id - a.id);
        const page = Number(params.get('page')) || 1, total = matches.length, start = (page - 1) * 10;
        res.setHeader('Content-Type', 'application/json'); res.end(JSON.stringify({ reports: matches.slice(start, start + 10), pagination: { total, page, lastPage: Math.max(1, Math.ceil(total / 10)), from: total ? start + 1 : null, to: Math.min(total, start + 10) } })); return;
    }
    if (url.pathname.startsWith('/details/')) {
        const report = reports.find(r => r.id === Number(url.pathname.split('/').pop()));
        res.setHeader('Content-Type', 'application/json'); res.end(JSON.stringify({ report: { ...report, description: 'Vehicle collision <script>window.injected=true</script>', response: 'Team is reviewing.', showUrl: '/full-report' } })); return;
    }
    res.setHeader('Content-Type', 'text/html');
    res.end(`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">${css}</head><body class="civilian-portal"><main style="max-width:1000px;margin:24px auto;padding:12px"><button type="button" class="btn btn-secondary" data-civilian-library-open="history">History View All</button><button type="button" class="btn btn-secondary" data-civilian-library-open="status">Status View All</button>${partial('reports/partials/civilian-drafts.blade.php')}</main>${partial('partials/civilian-report-library.blade.php')}<script type="module">import '/modules/civilian-report-drafts.js';import '/modules/civilian-report-library.js';import '/modules/shell-dialogs.js';</script></body></html>`);
});
(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve)); const origin = `http://127.0.0.1:${server.address().port}`;
    let browser;
    try {
        browser = await chromium.launch({ headless: true, ...(process.env.UI_BROWSER_PATH ? { executablePath: process.env.UI_BROWSER_PATH } : {}) });
        for (const width of [320, 390, 1366, 1920]) {
            const context = await browser.newContext({ viewport: { width, height: width < 600 ? 844 : 900 }, isMobile: width < 600, hasTouch: width < 600 });
            const page = await context.newPage(), errors = []; page.on('pageerror', error => errors.push(error.message));
            await page.goto(origin);
            const modal = page.locator('[data-library-modal]'), detail = page.locator('[data-library-detail]'), rows = modal.locator('tbody tr');
            const loaded = text => page.waitForFunction(value => document.querySelector('[data-library-count]').textContent === value && document.querySelector('[data-library-rows]').getAttribute('aria-busy') === 'false', text);
            await page.getByRole('button', { name: 'History View All', exact: true }).click(); await loaded('1–10 of 23 reports');
            await modal.getByRole('button', { name: 'Next', exact: true }).click(); await loaded('11–20 of 23 reports');
            await rows.first().getByRole('button', { name: /^View / }).click(); await detail.waitFor({ state: 'visible' });
            await detail.getByText('Team is reviewing.', { exact: true }).waitFor();
            assert.equal(await page.evaluate(() => window.injected), undefined);
            await detail.getByRole('button', { name: 'Back', exact: true }).click();
            assert.equal(await modal.getByText('Page 2 of 3', { exact: true }).count(), 1);
            assert.equal(await modal.evaluate(m => m.inert), false);
            await modal.getByLabel('Search', { exact: true }).fill('Market_2 50%');
            await modal.getByLabel('Status', { exact: true }).selectOption('resolved');
            await modal.getByLabel('Submitted / saved from').fill('2026-11-07'); await modal.getByLabel('Submitted / saved to').fill('2026-11-07');
            await modal.getByRole('button', { name: 'Search', exact: true }).click(); await loaded('1–10 of 11 reports');
            await modal.getByRole('button', { name: 'Page 2', exact: true }).click(); await loaded('11–11 of 11 reports');
            await page.keyboard.press('Escape'); assert(await modal.isHidden());
            await page.getByRole('button', { name: 'History View All', exact: true }).click(); await loaded('11–11 of 11 reports');
            await modal.getByRole('button', { name: 'Reset', exact: true }).click(); await loaded('1–10 of 23 reports');
            await page.screenshot({ path: path.join(output, `history-${width}.png`) });
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
            await page.keyboard.press('Escape');
            await page.getByRole('button', { name: 'Status View All', exact: true }).click(); await loaded('1–10 of 23 reports');
            assert.equal(await modal.locator('[data-library-date-heading]').textContent(), 'Latest status'); await page.keyboard.press('Escape');
            await page.evaluate(async () => {
                const { createCivilianDraftStore } = await import('/modules/civilian-draft-store.js'); const store = createCivilianDraftStore(1);
                for (let i = 0; i < 23; i++) await store.put({ submissionId: `00000000-0000-4000-8000-${String(i).padStart(12, '0')}`, fields: { description: `Unsent collision ${i}`, location_text: 'Market_2' }, state: 'offline', updatedAt: '2026-11-07T00:00:00Z', createdAt: '2026-11-07T00:00:00Z' });
                await createCivilianDraftStore(2).put({ submissionId: '00000000-0000-4000-8000-999999999999', fields: { description: 'SECRET-OTHER' }, updatedAt: '2026-11-07T00:00:00Z' });
            });
            await page.waitForFunction(() => document.querySelectorAll('[data-civilian-draft-list] article').length === 3);
            await page.locator('[data-civilian-library-open="drafts"]').click(); await loaded('1–10 of 23 drafts');
            assert(!(await modal.textContent()).includes('SECRET-OTHER'));
            await context.setOffline(true);
            await modal.getByRole('button', { name: 'Page 3', exact: true }).click(); await loaded('21–23 of 23 drafts');
            await rows.first().getByRole('button', { name: /^View / }).click();
            await detail.getByRole('button', { name: 'Continue draft', exact: true }).click();
            await detail.getByText(/Reconnect to open the report form/).waitFor();
            await detail.getByRole('button', { name: 'Back', exact: true }).click();
            await page.screenshot({ path: path.join(output, `drafts-${width}.png`) });
            await context.setOffline(false);
            page.on('dialog', dialog => dialog.accept());
            await rows.first().getByRole('button', { name: /^View / }).click();
            await detail.getByRole('button', { name: 'Delete draft', exact: true }).click(); await loaded('21–22 of 22 drafts');
            await modal.getByRole('button', { name: 'Previous', exact: true }).click(); await loaded('11–20 of 22 drafts');
            assert.deepEqual(errors, []);
            console.log(`${width}px: table, filters, numbered pages, nested details/back, XSS escaping, saved filters, 3-draft preview, offline/account-scoped drafts and deletion passed`);
            await context.close();
        }
    } finally { await browser?.close(); await new Promise(resolve => server.close(resolve)); }
})().catch(error => { console.error(error); process.exitCode = 1; });
