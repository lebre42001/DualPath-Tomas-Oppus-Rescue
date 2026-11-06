// Position is local to this browser/account; it is never sent to the server.
export function initializeUpdatesPosition(toggle, owner, { window: win = window, document: doc = document, storage } = {}) {
    const key = `aksyres-updates-position:v1:${owner}`;
    let position = null, active = null, suppressClick = false;
    try {
        storage ??= win.localStorage;
        const saved = JSON.parse(storage?.getItem(key) ?? 'null');
        if (saved && Number.isFinite(saved.x) && Number.isFinite(saved.y)) position = saved;
    } catch { /* Moving still works when local storage is unavailable. */ }
    const clamp = (value, min, max) => Math.max(min, Math.min(max, value));
    const bounds = () => {
        const viewport = win.visualViewport;
        const left = viewport?.offsetLeft ?? 0, top = viewport?.offsetTop ?? 0;
        const width = viewport?.width ?? win.innerWidth, height = viewport?.height ?? win.innerHeight;
        const rect = toggle.getBoundingClientRect();
        const style = win.getComputedStyle(toggle);
        const inset = side => 12 + (parseFloat(style.getPropertyValue(`--updates-safe-${side}`)) || 0);
        const nav = doc.querySelector('.mobile-quick-nav')?.getBoundingClientRect();
        const bottom = nav?.height > 0 && nav.top > top && nav.top < top + height ? nav.top : top + height - inset('bottom');
        const minX = left + inset('left'), minY = top + inset('top');
        return { minX, minY, maxX: Math.max(minX, left + width - rect.width - inset('right')), maxY: Math.max(minY, bottom - rect.height - 12) };
    };
    const place = (x, y) => {
        const b = bounds();
        const left = clamp(x, b.minX, b.maxX), top = clamp(y, b.minY, b.maxY);
        toggle.style.left = `${left}px`; toggle.style.top = `${top}px`;
        toggle.style.right = 'auto'; toggle.style.bottom = 'auto';
        return { x: (left - b.minX) / (b.maxX - b.minX || 1), y: (top - b.minY) / (b.maxY - b.minY || 1) };
    };
    const restore = () => {
        if (!position) return;
        const b = bounds();
        place(b.minX + position.x * (b.maxX - b.minX), b.minY + position.y * (b.maxY - b.minY));
    };
    const save = () => { try { storage?.setItem(key, JSON.stringify(position)); } catch { /* Keep the chosen position for this page. */ } };
    const finish = (event, cancelled = false) => {
        if (!active || event.pointerId !== active.id) return;
        const drag = active; active = null;
        toggle.classList.remove('is-dragging');
        if (drag.moved) {
            suppressClick = true;
            if (cancelled) place(drag.left, drag.top);
            else { position = place(drag.left + event.clientX - drag.x, drag.top + event.clientY - drag.y); save(); }
        }
        try { toggle.releasePointerCapture(drag.id); } catch { /* Capture can already be released. */ }
    };
    toggle.addEventListener('pointerdown', event => {
        if (active || event.isPrimary === false || (event.button != null && event.button !== 0)) return;
        suppressClick = false;
        const rect = toggle.getBoundingClientRect();
        active = { id: event.pointerId, x: event.clientX, y: event.clientY, left: rect.left, top: rect.top, moved: false };
        try { toggle.setPointerCapture(event.pointerId); } catch { /* Window listeners also handle mouse dragging. */ }
    });
    win.addEventListener('pointermove', event => {
        if (!active || event.pointerId !== active.id) return;
        const dx = event.clientX - active.x, dy = event.clientY - active.y;
        if (!active.moved && Math.hypot(dx, dy) < 6) return;
        active.moved = true; toggle.classList.add('is-dragging');
        event.preventDefault(); place(active.left + dx, active.top + dy);
    }, { passive: false });
    win.addEventListener('pointerup', event => finish(event));
    win.addEventListener('pointercancel', event => finish(event, true));
    toggle.addEventListener('lostpointercapture', event => finish(event, true));
    // A completed drag must not also open the modal. The next tap works normally.
    toggle.addEventListener('click', event => {
        if (!suppressClick) return;
        suppressClick = false; event.preventDefault(); event.stopImmediatePropagation();
    }, true);
    toggle.addEventListener('keydown', event => {
        suppressClick = false;
        const deltas = { ArrowLeft: [-16, 0], ArrowRight: [16, 0], ArrowUp: [0, -16], ArrowDown: [0, 16] };
        if (event.key === 'Home') {
            event.preventDefault(); position = null;
            for (const side of ['left', 'top', 'right', 'bottom']) toggle.style[side] = '';
            save();
        } else if (deltas[event.key]) {
            event.preventDefault();
            const rect = toggle.getBoundingClientRect(), [dx, dy] = deltas[event.key];
            position = place(rect.left + dx, rect.top + dy); save();
        }
    });
    const resized = () => {
        if (active) finish({ pointerId: active.id }, true);
        restore();
    };
    win.addEventListener('resize', resized);
    win.addEventListener('pageshow', restore);
    win.visualViewport?.addEventListener('resize', resized);
    win.visualViewport?.addEventListener('scroll', restore);
    restore();
}
