// Delegation also handles forms cloned into the report modal.
document.addEventListener('submit', async (event) => {
    const form = event.target.closest?.('[data-ai-analyze-form]');
    if (!form) return;
    event.preventDefault();
    if (form.dataset.busy === 'true') return;
    form.dataset.busy = 'true';
    const button = form.querySelector('button[type="submit"]');
    const originalLabel = button?.textContent;
    if (button) { button.disabled = true; button.textContent = 'Analyzing description…'; }
    const reportId = form.dataset.reportId;
    const modal = form.closest('[data-report-detail-modal]');
    const reviewForm = form.closest('[data-severity-review-for]');
    const draftNotes = reviewForm?.querySelector('[name="severity_review_notes"]')?.value;
    const selection = reviewForm?.querySelector('[name="severity_review_label"]');
    const defaultSelection = Array.from(selection?.options ?? []).find((option) => option.defaultSelected)?.value ?? '';
    const draftSelection = selection && (reviewForm?.dataset.selectionEdited === 'true' || selection.value !== defaultSelection)
        ? selection.value : null;
    const feedback = form.parentElement.querySelector('[data-ai-analysis-feedback]');
    if (feedback) { feedback.hidden = false; feedback.textContent = 'Analyzing description…'; }
    try {
        const response = await fetch(form.action, {
            method: 'POST', credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData(form),
        });
        const result = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(result.message || 'Analysis failed. Refresh your session and try again.');
        if (String(result.report_id) !== reportId || !result.analysis_html || !result.review_html) {
            throw new Error('Analysis returned an incomplete result. Please retry.');
        }
        document.querySelectorAll('[data-ai-analysis-for]').forEach((target) => {
            if (target.dataset.aiAnalysisFor !== reportId) return;
            const template = document.createElement('template');
            template.innerHTML = target.dataset.aiAnalysisLayout === 'compact' ? result.compact_html : result.analysis_html;
            target.replaceWith(template.content.cloneNode(true));
        });
        const reviewTemplate = document.createElement('template');
        reviewTemplate.innerHTML = result.review_html;
        const updatedReview = reviewTemplate.content.querySelector('[data-severity-review-for]');
        document.querySelectorAll('[data-severity-review-for]').forEach((target) => {
            if (target.dataset.severityReviewFor !== reportId || !updatedReview) return;
            const replacement = updatedReview.cloneNode(true);
            if (draftNotes) replacement.querySelector('[name="severity_review_notes"]').value = draftNotes;
            if (draftSelection !== null) {
                replacement.querySelector('[name="severity_review_label"]').value = draftSelection;
                replacement.dataset.selectionEdited = 'true';
            }
            target.replaceWith(replacement);
        });
        document.querySelectorAll('[data-ai-analysis-for], [data-severity-review-for]').forEach((target) => {
            if ((target.dataset.aiAnalysisFor ?? target.dataset.severityReviewFor) !== reportId) return;
            const message = target.querySelector('[data-ai-analysis-feedback]');
            if (message) { message.hidden = false; message.textContent = result.message; }
        });
        const visibleResult = modal?.querySelector('[data-ai-analysis-for]');
        visibleResult?.focus({ preventScroll: true });
        visibleResult?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    } catch (error) {
        if (feedback) { feedback.hidden = false; feedback.textContent = error.message || 'Unable to analyze. Please retry.'; }
    } finally {
        delete form.dataset.busy;
        if (button?.isConnected) { button.disabled = false; button.textContent = originalLabel; }
    }
});
