/* Isolated mobile browser fixtures. This server never forwards a report to the
   real Laravel app or online deployment. Uses the actual draft UI/store modules. */
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '..');
const web = path.join(root, 'web_system');
const output = path.join(__dirname, 'runtime/civilian-drafts');
fs.mkdirSync(output, { recursive: true });
const received = new Set(), posted = [], errors = [];
let origin;
const partial = fs.readFileSync(path.join(web, 'resources/views/reports/partials/civilian-drafts.blade.php'), 'utf8');
const manifest = JSON.parse(fs.readFileSync(path.join(web, 'public/build/manifest.json')));
const css = ['resources/css/app.css', 'resources/css/operations.css'].map(key => `<link rel="stylesheet" href="/build/${manifest[key].file}">`).join('');
const form = id => `<section class="panel"><h1>Send emergency report</h1><form data-report-draft-form data-report-role="civilian" action="/reports" method="post">
<input type="hidden" name="_token" value="isolated-browser-fixture"><input type="hidden" name="submission_id" value="${id}">
<input type="hidden" name="latitude" value="10.35"><input type="hidden" name="longitude" value="124.97"><input type="hidden" name="liveness_proof" value="${'x'.repeat(64)}">
<label>Scene photo<input type="file" name="evidence" accept="image/*"></label><input hidden type="file" name="evidence_photo_capture"><input hidden type="file" name="selfie"><input hidden type="file" name="selfie_capture">
<label>Location<input class="input" name="location_text" required></label><label>Description<textarea class="textarea" name="description" required></textarea></label>
<button type="submit" class="btn btn-primary" data-report-submit>Send Report</button><button type="button" class="btn btn-secondary" data-civilian-draft-save>Save draft</button><p data-civilian-draft-feedback role="status"></p></form></section>
<script>window.fixtureVerified=true; const f=document.querySelector('form');
f.addEventListener('submit',e=>{if(!window.fixtureVerified)e.preventDefault()});
f.addEventListener('stitch:report-draft-restored',()=>{window.fixtureVerified=false;f.elements.latitude.value='';f.elements.longitude.value='';f.elements.liveness_proof.value=''})</script>`;
const server = http.createServer((request, response) => {
    const url = new URL(request.url, origin || 'http://localhost');
    if (url.pathname.startsWith('/modules/')) {
        const name = path.basename(url.pathname).replace(/\.js$/, '') + '.js';
        if (!['civilian-report-drafts.js', 'civilian-draft-store.js', 'civilian-draft-recovery.js', 'report-delivery.js', 'dom-ready.js'].includes(name)) { response.writeHead(404).end(); return; }
        response.setHeader('Content-Type', 'application/javascript'); response.end(fs.readFileSync(path.join(web, 'resources/js', name))); return;
    }
    if (url.pathname.startsWith('/build/')) {
        const file = path.join(web, 'public/build/assets', path.basename(url.pathname));
        if (!fs.existsSync(file)) { response.writeHead(404).end(); return; }
        response.setHeader('Content-Type', file.endsWith('.css') ? 'text/css' : 'application/javascript'); response.end(fs.readFileSync(file)); return;
    }
    if (url.pathname.startsWith('/reports/submissions/')) {
        const id = path.basename(url.pathname); response.setHeader('Content-Type', 'application/json');
        response.end(JSON.stringify({ received: received.has(id), redirect_url: received.has(id) ? origin+'/reports/7/success' : null })); return;
    }
    if (url.pathname === '/reports/7/success') { response.end('<h1>Report received</h1>'); return; }
    const owner = url.searchParams.get('owner') === '2' ? '2' : '1';
    const panel = partial.replace(/{{ auth\(\)->id\(\) }}/g, owner)
        .replace(/{{ route\('reports.create'\) }}/g, origin+'/form')
        .replace(/{{ route\('reports.submission', '__submission__'\) }}/g, origin+'/reports/submissions/__submission__');
    const context = `<span hidden data-civilian-draft-context data-owner="${owner}" data-create-url="${origin}/form" data-receipt-url="${origin}/reports/submissions/__submission__"></span>`;
    const navigation = '<nav><a href="/history" data-civilian-draft-nav>Draft Reports <b data-civilian-draft-nav-count hidden>0</b></a></nav>';
    response.setHeader('Content-Type', 'text/html');
    response.end(`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">${css}</head><body class="civilian-portal">${context}<main style="max-width:560px;margin:auto;padding:12px;display:grid;gap:12px">${navigation}${url.pathname==='/history'?panel:form(crypto.randomUUID())}</main><script type="module" src="/modules/civilian-report-drafts.js"></script></body></html>`);
});

(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    origin = 'http://127.0.0.1:'+server.address().port;
    const browser = await chromium.launch({ headless: true, ...(process.env.UI_BROWSER_PATH ? { executablePath: process.env.UI_BROWSER_PATH } : {}) });
    try {
        const context = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, serviceWorkers: 'block' });
        const page = await context.newPage(); page.on('pageerror', e => errors.push(e.message));
        let accept = false, lostResponse = false;
        await page.route('**/reports', async route => {
            const data = route.request().postDataBuffer().toString();
            const id = data.match(/name="submission_id"\r\n\r\n([\da-f-]+)/i)?.[1];
            assert(id); posted.push(id);
            if (accept || lostResponse) received.add(id);
            if (!accept || lostResponse) return route.abort('internetdisconnected');
            return route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ received: true, redirect_url: origin+'/reports/7/success' }) });
        });
        const list = () => page.evaluate(async () => {
            const {createCivilianDraftStore}=await import('/modules/civilian-draft-store.js');
            return (await createCivilianDraftStore(1).list()).map(x=>({ id:x.submissionId, state:x.state, attempted:x.attempted, photo:x.evidenceFile instanceof Blob, fields:x.fields }));
        });
        const fill = async () => {
            await page.locator('input[name="evidence"]').setInputFiles({name:'scene.png',mimeType:'image/png',buffer:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aKXcAAAAASUVORK5CYII=','base64')});
            await page.locator('[name="location_text"]').fill('Market entrance');
            await page.locator('[name="description"]').fill('Vehicle collision beside the market.');
            await page.waitForFunction(()=>document.querySelector('[data-civilian-draft-nav-count]')?.textContent==='1');
            assert.equal(await page.locator('[data-civilian-draft-list]').count(), 0, 'Draft lists live only on their dedicated page');
        };
        await page.goto(origin+'/form'); await fill();
        await page.evaluate(()=>window.fixtureVerified=false);
        await page.locator('[data-report-submit]').click(); assert.equal(posted.length,0,'An existing validation gate still blocks transmission');
        await page.evaluate(()=>window.fixtureVerified=true);
        await page.locator('[data-report-submit]').click();
        await page.waitForFunction(()=>document.querySelector('[data-civilian-draft-feedback]')?.textContent.includes('connection was interrupted'));
        assert.equal(posted.length,1); const original = posted[0];
        assert.equal((await list())[0].photo,true);
        await page.reload(); await page.getByRole('link',{name:/Draft Reports/}).click();
        await page.getByRole('button',{name:'Continue',exact:true}).click();
        await page.waitForFunction(id=>document.querySelector('[name="submission_id"]').value===id,original);
        assert.equal(await page.locator('[name="description"]').inputValue(),'Vehicle collision beside the market.');
        assert.equal(await page.locator('[name="evidence"]').evaluate(e=>e.files.length),1);
        assert.equal(await page.locator('[name="latitude"]').inputValue(),'');
        assert.equal(await page.locator('[name="liveness_proof"]').inputValue(),'');
        await context.setOffline(true);
        await page.waitForFunction(()=>document.querySelector('[data-civilian-draft-feedback]')?.textContent.includes('Connection lost'));
        await page.locator('[data-report-submit]').click(); assert.equal(posted.length,1);
        await context.setOffline(false); assert.equal(posted.length,1,'Reconnection never auto-sends an emergency');
        await page.goto(origin+'/history?owner=2');
        assert.equal(await page.locator('[data-civilian-draft-list]').evaluate(node=>node.children.length),0,'Another account cannot see the draft');
        assert.equal(await page.locator('[data-civilian-draft-empty]').isVisible(),true);
        assert.equal(await page.locator('[data-civilian-draft-nav-count]').isVisible(),false);
        await page.goto(origin+'/history'); await page.getByRole('button',{name:'Continue',exact:true}).waitFor();
        assert.equal((await list())[0].id,original);
        for (const width of [320,390]) {
            await page.setViewportSize({width,height:844});
            assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Mobile draft list must not overflow');
        }
        await page.screenshot({path:path.join(output,'drafts-mobile.png'),fullPage:true});
        await page.evaluate(()=>document.documentElement.dataset.theme='dark');
        const contrast = await page.getByRole('button',{name:'Delete draft',exact:true}).evaluate(async button => {
            // Theme colors transition for a fraction of a second. Check the
            // settled colors, rather than a transient blended background.
            await Promise.all(button.getAnimations().map(animation=>animation.finished.catch(()=>{})));
            const style=getComputedStyle(button);
            const luminance=color=>{const values=color.match(/[\d.]+/g).slice(0,3).map(Number).map(n=>n/255).map(n=>n<=.04045?n/12.92:((n+.055)/1.055)**2.4);return values[0]*.2126+values[1]*.7152+values[2]*.0722};
            const a=luminance(style.color),b=luminance(style.backgroundColor);return (Math.max(a,b)+.05)/(Math.min(a,b)+.05);
        });
        assert(contrast>=4.5,'Dark mode draft actions must remain readable');
        await page.screenshot({path:path.join(output,'drafts-mobile-dark.png'),fullPage:true});
        await page.getByRole('button',{name:'Continue',exact:true}).click();
        await page.waitForFunction(id=>document.querySelector('[name="submission_id"]')?.value===id,original);
        accept=true; await page.evaluate(()=>window.fixtureVerified=true);
        await page.locator('[data-report-submit]').click(); await page.waitForURL('**/reports/7/success');
        assert.equal(posted[1],original,'A resumed transmission retains its original submission ID');
        assert.deepEqual(await list(),[],'Only the confirmed report draft is cleared');
        await page.goto(origin+'/form'); await fill();
        lostResponse=true;
        await page.locator('[data-report-submit]').click();
        await page.waitForFunction(()=>document.querySelector('[data-civilian-draft-feedback]')?.textContent.includes('connection was interrupted'));
        const before=posted.length;
        await page.reload(); await page.getByRole('link',{name:/Draft Reports/}).click();
        await page.getByRole('button',{name:'Continue',exact:true}).click();
        await page.waitForURL('**/reports/7/success');
        assert.equal(posted.length,before,'A receipt found after response loss prevents another upload');
        assert.deepEqual(await list(),[]); assert.deepEqual(errors,[]);
        console.log('PASS mobile browser: dedicated draft page, account navigation badge, validation gate, interrupted upload, IndexedDB photo/reload restoration, stable submission ID, fresh GPS/live-check reset, account isolation, offline draft, no automatic retry, receipt reconciliation, 320/390px and dark layout.');
        fs.writeFileSync(path.join(output,'results.json'),JSON.stringify({passed:true,cases:10,errors},null,2));
        await context.close();
    } finally { await browser.close(); server.closeAllConnections(); await new Promise(resolve=>server.close(resolve)); }
})().catch(error=>{console.error(error);server.closeAllConnections();server.close();process.exitCode=1});
