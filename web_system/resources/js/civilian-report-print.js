import '../css/civilian-report-print.css';

const print = () => window.print();
const button = document.querySelector('[data-print-reports]');
button?.addEventListener('click', print);
const options = document.querySelector('[data-report-print-options]');
const readyToPrint = () => {
    const missing = [...document.querySelectorAll('[data-print-scene-photo]')].some(img => !img.complete || img.naturalWidth === 0);
    if (missing) {
        const feedback = document.querySelector('[data-print-feedback]');
        if (feedback) { feedback.hidden = false; feedback.textContent = 'The scene photo could not load. Update the preview to retry, or clear the photo option.'; }
        return;
    }
    print();
};
if ((options && document.body.dataset.autoPrint === 'true') || (!options && button && !button.disabled)) {
    if (document.readyState === 'complete') readyToPrint();
    else window.addEventListener('load', readyToPrint, { once: true });
}
