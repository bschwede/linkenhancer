// Copy-to-clipboard for xref-detail-tab identifier values.
// Delegated document-level listener — works for lazy-loaded tab content.
// Uses Clipboard API with execCommand('copy') fallback.

const FLICKER_MS = 1500;

document.addEventListener('click', (event) => {
    const btn = event.target.closest('[data-le-copy]');
    if (!btn) {
        return;
    }

    const li = btn.closest('.le-xref-identifiers__item');
    const valueEl = li?.querySelector('.le-xref-identifiers__value[data-copy-value]');
    if (!valueEl) {
        return;
    }

    const text = valueEl.getAttribute('data-copy-value') ?? '';
    if (!text) {
        return;
    }

    event.preventDefault();

    const flick = () => {
        btn.setAttribute('data-le-copied', '1');
        setTimeout(() => btn.removeAttribute('data-le-copied'), FLICKER_MS);
    };

    if (navigator.clipboard?.writeText) {
        navigator.clipboard.writeText(text).then(flick).catch(() => fallback(text, flick));
    } else {
        fallback(text, flick);
    }
});

function fallback(text, done) {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try {
        document.execCommand('copy');
        done();
    } catch {
        /* ignore */
    }
    document.body.removeChild(ta);
}
