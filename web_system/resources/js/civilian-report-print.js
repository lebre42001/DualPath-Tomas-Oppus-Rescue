import '../css/civilian-report-print.css';

const print = () => window.print();
const button = document.querySelector('[data-print-reports]');
button?.addEventListener('click', print);
if (button && !button.disabled) {
    if (document.readyState === 'complete') print();
    else window.addEventListener('load', print, { once: true });
}
