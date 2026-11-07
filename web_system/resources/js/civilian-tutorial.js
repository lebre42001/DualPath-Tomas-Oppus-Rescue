import { onDomReady } from './dom-ready.js';
import { createTutorialProgress, tutorialPlacement } from './civilian-tutorial-state.js';

export const CIVILIAN_TUTORIAL_STEPS = [
    { title: 'Welcome to AksyRes', copy: 'Learn the tabs and how to report a road accident with this quick tour. Next continues; Skip lets you browse right away. Verification is required before a real report. This tutorial sends no report.' },
    { title: 'Home', selector: '[data-civilian-tour-home]', copy: 'Home shows your report totals and latest response status. Quick tutorial here or in Settings lets you replay these steps whenever you need.' },
    { title: 'Report / Verify Account', selector: '[data-civilian-tour-report]', copy: 'Start a road-accident report here. If your account is not verified, this opens account verification first. You can finish this tutorial and browse before verifying.' },
    { title: '1. Add a scene photo', demo: 'photo', copy: 'In the report form, use Capture Photo or Upload Photo to attach a clear JPG, PNG or WebP scene photo. This is a preview; it does not open your camera or upload a file.' },
    { title: '2. Get your location', demo: 'gps', copy: 'Keep phone Location turned on. Tap Get GPS to allow access on your first report. When your browser remembers the permission, Report gets a fresh location automatically. Add the barangay, road or landmark so responders can find the scene.' },
    { title: '3. Describe the accident', demo: 'description', copy: 'Briefly explain what happened, visible injuries, people involved and nearby landmarks. Report only what you know. The report form currently supports Road Accident.' },
    { title: '4. Complete the live check', demo: 'selfie', copy: 'When you tap Send Report, the app asks for a fresh front-camera selfie and blink check before transmission. Account verification and this report check are separate. Neither runs during this tour.' },
    { title: '5. Wait for a receipt', demo: 'receipt', copy: 'After the required photo, GPS, description and live check pass, wait for the server receipt. A saved draft or a successful connection check does not mean responders received your report.' },
    { title: 'Draft Reports', nav: 'drafts', copy: 'Unfinished or interrupted reports are saved for your account on this device when storage is available. View All has search, dates and pages. Continue the same draft after reconnecting; reports are never sent automatically.' },
    { title: 'Report History & Status', selector: '[data-civilian-tour-status]', copy: 'My Reports & Status shows your submitted reports and admin response updates. View All opens the searchable table; View report opens details. Only your own reports appear.' },
    { title: 'Profile', nav: 'profile', copy: 'Profile contains your personal and contact information. Keep these details accurate so the response team can identify and contact you.' },
    { title: 'Settings & Device Check', nav: 'settings', copy: 'Settings has alert preferences and light/dark display mode. Device Check helps review permissions. You can also replay this Quick tutorial from Settings.' },
    { title: 'System Log', nav: 'log', copy: 'System Log shows recorded account sign-ins/sign-outs, report/admin actions and app activity. Search by text, date or type and use Copy text when you need a copy.' },
    { title: 'Floating Updates badge', selector: '[data-civilian-updates-toggle]', copy: 'Drag this badge to a convenient spot. Tap it for unread updates, readable What’s new notes, Check again and app builds. Update app appears when a newer server build is available.' },
    { title: 'Menu & Log Out', selector: '.sidebar-logout button', drawer: true, copy: 'The sidebar holds all your navigation and Log Out. Use Log Out when you finish on a shared device. On smaller screens, the menu button opens this sidebar.' },
    { title: 'You are ready', selector: '[data-civilian-tour-report]', copy: 'Select Done to finish and browse. If your account is not verified, choose Verify Account when ready, allow the front camera, and complete the face/blink check. Real reporting stays locked until verification succeeds.' },
];

export function initializeCivilianTutorial(root, { document: doc = document, window: win = window, storage } = {}) {
    const progress = createTutorialProgress(root.dataset.owner, { storage });
    const field = name => root.querySelector(`[data-tutorial-${name}]`);
    const callout = field('callout'), spotlight = field('spotlight'), demo = field('demo');
    let links = {}; try { links = JSON.parse(root.dataset.navLinks); } catch { /* A missing link falls back to the explanation. */ }
    let active = false, step = 0, target = null, sequence = 0, openedDrawer = false, frame, scrollBefore, lastOpener;
    const visible = node => {
        if (!node || !node.getClientRects().length || win.getComputedStyle(node).visibility === 'hidden') return false;
        const sidebar = node.closest('[data-sidebar]');
        // A drawer can remain CSS-visible during its closing transition.
        return !sidebar || win.getComputedStyle(sidebar).position !== 'fixed' || sidebar.classList.contains('is-open');
    };
    const choose = selector => [...doc.querySelectorAll(selector)].find(visible);
    const viewport = () => ({ left: win.visualViewport?.offsetLeft ?? 0, top: win.visualViewport?.offsetTop ?? 0, width: win.visualViewport?.width ?? win.innerWidth, height: win.visualViewport?.height ?? win.innerHeight });
    const layout = () => {
        if (!active) return;
        const view = viewport();
        const rect = visible(target) ? target.getBoundingClientRect() : null;
        const clipped = rect ? { left: Math.max(view.left + 4, rect.left - 5), top: Math.max(view.top + 4, rect.top - 5), right: Math.min(view.left + view.width - 4, rect.right + 5), bottom: Math.min(view.top + view.height - 4, rect.bottom + 5) } : null;
        const pointing = clipped && clipped.right > clipped.left && clipped.bottom > clipped.top;
        root.dataset.pointing = String(Boolean(pointing)); spotlight.hidden = !pointing;
        if (pointing) Object.assign(spotlight.style, { left: `${clipped.left}px`, top: `${clipped.top}px`, width: `${clipped.right - clipped.left}px`, height: `${clipped.bottom - clipped.top}px` });
        const box = callout.getBoundingClientRect(), position = tutorialPlacement(pointing ? clipped : null, box, view);
        Object.assign(callout.style, { left: `${position.left}px`, top: `${position.top}px` });
        callout.dataset.placement = position.placement;
        callout.style.setProperty('--tutorial-arrow-left', `${position.arrowLeft ?? 0}px`);
        callout.style.setProperty('--tutorial-arrow-top', `${position.arrowTop ?? 0}px`);
    };
    const queueLayout = () => { win.cancelAnimationFrame(frame); frame = win.requestAnimationFrame(layout); };
    const closeDrawer = () => {
        if (openedDrawer) doc.querySelector('[data-sidebar-close]')?.click();
        openedDrawer = false;
    };
    const finish = (status = 'skipped', restore = true) => {
        if (!active) return;
        if (status) progress.save(status, step);
        active = false; sequence++; target = null; win.cancelAnimationFrame(frame);
        closeDrawer();
        doc.dispatchEvent(new CustomEvent('stitch:dialog-close', { detail: { modal: root } }));
        root.hidden = true; demo.hidden = true;
        if (restore && scrollBefore) win.scrollTo(scrollBefore.x, scrollBefore.y);
        if (restore && visible(lastOpener)) lastOpener.focus({ preventScroll: true });
    };
    const show = async index => {
        if (!active) return;
        const current = ++sequence;
        step = Math.max(0, Math.min(CIVILIAN_TUTORIAL_STEPS.length - 1, index));
        const entry = CIVILIAN_TUTORIAL_STEPS[step], final = step === CIVILIAN_TUTORIAL_STEPS.length - 1;
        if (!entry.nav && !entry.drawer) closeDrawer();
        target = null;
        progress.save('active', step);
        field('title').textContent = entry.title; field('copy').textContent = entry.copy;
        field('progress').textContent = `Step ${step + 1} of ${CIVILIAN_TUTORIAL_STEPS.length}`;
        field('back').disabled = step === 0; field('next').textContent = final ? 'Done' : 'Next';
        field('next').disabled = true; field('skip').hidden = final;
        field('verify').hidden = !final || root.dataset.verified === 'true';
        demo.hidden = !entry.demo;
        for (const node of root.querySelectorAll('[data-tutorial-example]')) node.hidden = node.dataset.tutorialExample !== entry.demo;
        const find = () => entry.demo ? field('demo') : entry.nav
            ? [...doc.querySelectorAll('a[href]')].find(node => node.href === links[entry.nav] && visible(node))
            : entry.selector ? choose(entry.selector) : null;
        layout();
        target = find();
        if (!target && (entry.nav || entry.drawer)) {
            const reopen = choose('[data-sidebar-reopen]');
            if (reopen) {
                openedDrawer = !doc.querySelector('[data-sidebar]')?.classList.contains('is-open');
                if (openedDrawer) reopen.click();
                await new Promise(resolve => win.setTimeout(resolve, 230));
                if (!active || current !== sequence) return;
                target = find();
            }
        }
        if (!entry.demo) target?.scrollIntoView({ behavior: 'auto', block: 'center', inline: 'nearest' });
        if (!active || current !== sequence) return;
        callout.scrollTop = 0; field('next').disabled = false;
        layout(); queueLayout(); field('next').focus({ preventScroll: true });
    };
    const start = (opener, resume = false) => {
        if (active) return;
        const otherDialog = [...doc.querySelectorAll('[role="dialog"]')].find(node => node !== root && visible(node));
        if (otherDialog) return;
        lastOpener = opener; scrollBefore = { x: win.scrollX, y: win.scrollY };
        active = true; root.hidden = false;
        doc.dispatchEvent(new CustomEvent('stitch:dialog-open', { detail: { modal: root, close: () => finish('skipped'), opener } }));
        void show(resume ? progress.value()?.step ?? 0 : 0);
    };
    field('next').addEventListener('click', () => { if (step === CIVILIAN_TUTORIAL_STEPS.length - 1) finish('done'); else void show(step + 1); });
    field('back').addEventListener('click', () => { if (step > 0) void show(step - 1); });
    field('skip').addEventListener('click', () => finish('skipped'));
    field('verify').addEventListener('click', () => finish('done', false));
    for (const trigger of doc.querySelectorAll('[data-civilian-tutorial-start]')) trigger.addEventListener('click', () => start(trigger));
    win.addEventListener('resize', () => { if (active) void show(step); });
    win.visualViewport?.addEventListener('resize', () => { if (active) void show(step); });
    win.visualViewport?.addEventListener('scroll', queueLayout);
    doc.addEventListener('scroll', queueLayout, true);
    win.addEventListener('pagehide', () => finish(null, false));
    win.addEventListener('pageshow', event => { if (event.persisted && root.dataset.auto === 'true' && progress.shouldStart()) start(doc.querySelector('[data-civilian-tutorial-start]'), true); });
    const url = new URL(win.location.href), replay = url.searchParams.get('tutorial') === '1';
    if (replay) { url.searchParams.delete('tutorial'); win.history.replaceState(win.history.state, '', url); }
    if (replay || (root.dataset.auto === 'true' && progress.shouldStart())) win.setTimeout(() => start(doc.querySelector('[data-civilian-tutorial-start]'), !replay), 350);
}

onDomReady(() => { const root = document.querySelector('[data-civilian-tutorial]'); if (root) initializeCivilianTutorial(root); });
