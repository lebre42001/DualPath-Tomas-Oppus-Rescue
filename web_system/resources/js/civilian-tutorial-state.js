export function createTutorialProgress(owner, { storage } = {}) {
    if (!/^\d+$/.test(String(owner))) throw new Error('A civilian account is required.');
    if (storage === undefined) { try { storage = globalThis.localStorage; } catch { storage = null; } }
    const key = `aksyres-civilian-tutorial:v1:${owner}`;
    let value = null;
    try { value = JSON.parse(storage?.getItem(key) ?? 'null'); } catch { /* Offer the tour if saved progress is unreadable. */ }
    if (!value || !['active', 'done', 'skipped'].includes(value.status) || !Number.isInteger(value.step) || value.step < 0) value = null;
    return {
        value: () => value,
        shouldStart: () => !value || value.status === 'active',
        save(status, step) {
            value = { status, step };
            try { storage?.setItem(key, JSON.stringify(value)); } catch { /* Skip/Done still work for this visit. */ }
        },
    };
}

export function tutorialPlacement(target, card, viewport) {
    const pad = 12, gap = 16;
    const leftEdge = (viewport.left ?? 0) + pad, topEdge = (viewport.top ?? 0) + pad;
    const rightEdge = (viewport.left ?? 0) + viewport.width - pad, bottomEdge = (viewport.top ?? 0) + viewport.height - pad;
    const width = Math.min(card.width, Math.max(0, rightEdge - leftEdge));
    const height = Math.min(card.height, Math.max(0, bottomEdge - topEdge));
    const clamp = (value, min, max) => Math.max(min, Math.min(max, value));
    if (!target) return { left: leftEdge + (rightEdge - leftEdge - width) / 2, top: topEdge + (bottomEdge - topEdge - height) / 2, placement: 'center' };
    const center = (target.left + target.right) / 2;
    const left = clamp(center - width / 2, leftEdge, rightEdge - width);
    let top, placement;
    if (target.bottom + gap + height <= bottomEdge) { top = target.bottom + gap; placement = 'below'; }
    else if (target.top - gap - height >= topEdge) { top = target.top - gap - height; placement = 'above'; }
    else { top = clamp(target.bottom + gap, topEdge, bottomEdge - height); placement = 'center'; }
    top = clamp(top, topEdge, bottomEdge - height);
    return { left, top, placement, arrowLeft: clamp(center - 6, left + 18, left + width - 30), arrowTop: placement === 'below' ? top - 6 : top + height - 6 };
}
