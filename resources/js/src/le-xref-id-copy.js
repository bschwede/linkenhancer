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
        navigator.clipboard.writeText(text).then(flick).catch(() => {
            if (!copyViaExecCommand(text)) {
                console.warn('LE: clipboard copy failed (Clipboard API rejected, execCommand blocked)');
            }
        });
    } else if (!copyViaExecCommand(text)) {
        // Non-secure (HTTP) context: Clipboard API unavailable. execCommand is
        // the only route — it returns false when the browser refuses (e.g. no
        // committed selection in a fixed modal overlay). Do NOT flash green.
        console.warn('LE: clipboard copy failed (execCommand not permitted in this context)');
    }
});

/**
* Copy via a transient textarea + execCommand('copy'). Used on non-secure
* (HTTP) origins where the async Clipboard API is unavailable.
*
* @param {string} text
* @returns {boolean} true only if execCommand actually committed the text
*/
function copyViaExecCommand(text) {
    const ta = document.createElement('textarea');
    ta.value = text;
    Object.assign(ta.style, {
        position: 'fixed',
        top: '0',
        left: '0',
        width: '1px',
        height: '1px',
        padding: '0',
        border: '0',
        fontSize: '12px',
        opacity: '0',
        overflow: 'hidden',
    });

    // Host inside the open modal when present, so the helper textarea lives in
    // the currently-active stacking/focus context (Bootstrap fixed overlay)
    // rather than behind it in document.body.
    const host = document.querySelector('.modal.show') ?? document.body;
    host.appendChild(ta);
    ta.focus();
    ta.select();
    ta.setSelectionRange(0, ta.value.length);
    let ok = false;
    try {
        ok = document.execCommand('copy') === true;
    } catch {
        ok = false;
    }
    host.removeChild(ta);
    return ok;
}