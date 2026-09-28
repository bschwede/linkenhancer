import { createSafeFilter } from "./wthb-filter.js";
import { getHelpTitle, setWthbLinkClickHandler } from "./wthb-logic.js";

export const insertSubcontextLinks = (
    document,
    window,
    bootstrap,
    cfg
) => {
    const is_touch_device = ('ontouchstart' in window) || (window.DocumentTouch && document instanceof DocumentTouch);

    const newPopover = (el, options) => new bootstrap.Popover(
        el,
        Object.assign({
            html: true,
            container: 'body',
            sanitize: false,
            placement: 'bottom',
            trigger: is_touch_device ? 'click' : 'focus hover',   // remains as long as the element is focused
            delay: { "show": 100, "hide": 3000 },
        }, options)
    );

    const contexts = cfg.subcontext;

    if (!Array.isArray(contexts) || contexts.length === 0) return;

    // delegated event handler
    const popoverTimeouts = new Map(); // trigger -> timeout-ID
    const activePopovers = new WeakMap(); // trigger element -> popover instance

    // central delegation: operates on all .popover-trigger
    const setupDelegation = () => {
        // Remove existing listeners first (once)
        document.removeEventListener('show.bs.popover', handleShowPopover);
        document.removeEventListener('shown.bs.popover', handleShownPopover);
        document.removeEventListener('hide.bs.popover', handleHidePopover);

        // Bind delegated handlers
        document.addEventListener('show.bs.popover', handleShowPopover, true);
        document.addEventListener('shown.bs.popover', handleShownPopover, true);
        document.addEventListener('hide.bs.popover', handleHidePopover, true);
    };

    const handleShowPopover = (e) => {
        const trigger = e.target.closest('.popover-trigger');
        if (!trigger) return;

        // close all other popovers
        document.querySelectorAll('.popover-trigger').forEach(otherTrigger => {
            if (otherTrigger !== trigger) {
                const otherPopover = activePopovers.get(otherTrigger);
                if (otherPopover) otherPopover.hide();
            }
        });
    };

    const handleShownPopover = (e) => {
        const trigger = e.target.closest('.popover-trigger');
        if (!trigger) return;

        // auto hide timer (5s)
        const timeoutId = setTimeout(() => {
            const popover = activePopovers.get(trigger);
            if (popover) popover.hide();
            popoverTimeouts.delete(trigger);
        }, 5000);

        popoverTimeouts.set(trigger, timeoutId);
    };

    const handleHidePopover = (e) => {
        const trigger = e.target.closest('.popover-trigger');
        if (!trigger) return;

        // delete timer
        const timeoutId = popoverTimeouts.get(trigger);
        if (timeoutId) {
            clearTimeout(timeoutId);
            popoverTimeouts.delete(trigger);
        }
    };

    const createPopoverForTrigger = (trigger, url, pos = 'bottom') => {
        if (typeof url !== 'string' || !/^(https?:)?\/\//i.test(url)) {
            return;
        }
        const target = (cfg.openInNewTab ? 'target="_blank" ' : '');
        const helptitle = getHelpTitle(url, cfg);
        
        const aEl = document.createElement('a');
        aEl.href = url;
        aEl.target = cfg.openInNewTab ? '_blank' : '';
        aEl.className = 'stretched-link d-inline-block p-1 text-decoration-none';
        aEl.innerHTML = '<i class="fa-solid fa-circle-question"></i> ' + helptitle;

        setWthbLinkClickHandler(document, window, bootstrap, cfg, aEl);

        const popover = newPopover(trigger, {
            placement: pos,
            content: aEl
        });

        activePopovers.set(trigger, popover);
        return popover;
    };

    // initial and dynamic rebind
    const initializeTriggers = () => {
        document.querySelectorAll('.popover-trigger').forEach(trigger => {
            if (activePopovers.has(trigger)) return; // already initialized

            // find context via data attribute
            const ctxData = trigger.dataset?.subcontext;
            if (!ctxData) return;

            const { url, pos } = JSON.parse(ctxData);
            createPopoverForTrigger(trigger, url, pos);
        });
    };

    // Resolve the target node for a context definition (JSON {f,e,p} or CSS selector).
    // Returns { node, pos, invalid }; node is null when not found, invalid marks a
    // malformed JSON context (not retried).
    const findContextNode = (ctx) => {
        let pos = "top";

        if (ctx.startsWith("{")) { // JSON object: {f:filter, e:JS, p:position} e or f needed, p optional
            let ctxobj;
            try {
                ctxobj = JSON.parse(ctx);
            } catch (e) {
                return { node: null, pos, invalid: true };
            }
            let node = null;
            if (ctxobj?.e ?? null) {
                const filterFn = createSafeFilter(document, ctxobj.e);
                const result = filterFn ? filterFn() : null;
                node = Array.isArray(result) ? (result[0] ?? null) : (result ?? null);
            } else {
                node = ctxobj?.f ? document.querySelector(ctxobj.f) : null;
            }
            pos = ctxobj?.p ?? pos;
            return { node, pos };
        }

        return { node: document.querySelector(ctx), pos }; // must be a filter expression
    };

    // Attach the ⓘ trigger to a resolved node (guarded against double-attach).
    const attachTrigger = (node, url, pos) => {
        if (node.querySelector?.('.popover-trigger')) return;

        const poptrigger = document.createElement('span');
        poptrigger.className = 'popover-trigger';
        poptrigger.dataset.subcontext = JSON.stringify({ url, pos });
        poptrigger.textContent = 'ⓘ';
        node.appendChild(poptrigger);

        createPopoverForTrigger(poptrigger, url, pos);
    };

    // Contexts whose target node was not present yet (e.g. a tab injected
    // asynchronously via le-xref-detail-tab.js). Re-checked on DOM mutation
    // until found or the timeout expires.
    const PENDING_TIMEOUT_MS = Number(cfg.subcontext_pending_timeout) > 0 ? Number(cfg.subcontext_pending_timeout) : 5000;
    const pendingContexts = [];
    const pendingStart = Date.now();
    let pendingExpired = false;

    const processPendingContexts = () => {
        if (pendingExpired || pendingContexts.length === 0) return;
        if (Date.now() - pendingStart > PENDING_TIMEOUT_MS) {
            pendingExpired = true;
            pendingContexts.length = 0;
            return;
        }
        for (let i = pendingContexts.length - 1; i >= 0; i--) {
            const { ctx, url } = pendingContexts[i];
            const { node, pos } = findContextNode(ctx);
            if (node) {
                attachTrigger(node, url, pos);
                pendingContexts.splice(i, 1);
            }
        }
    };

    contexts.forEach((elem) => {
        const ctx = elem.ctx.trim();
        const url = elem.url;
        if (!ctx || !url) return;

        const { node, pos, invalid } = findContextNode(ctx);
        if (invalid) {
            console.warn('LE-mod wthb subcontext:', ctx);
            return;
        }
        if (!node) {
            pendingContexts.push({ ctx, url }); // target not present yet - retry later
            return;
        }

        attachTrigger(node, url, pos);
    });

    // mutation observer
    const observer = new MutationObserver((mutations) => {
        let shouldReinit = false;
        const shouldProcessPending = pendingContexts.length > 0 && !pendingExpired;
        mutations.forEach(mutation => {
            if (mutation.type === 'childList') {
                mutation.addedNodes.forEach(node => {
                    if (node.nodeType === Node.ELEMENT_NODE &&
                        node.querySelector?.('.popover-trigger')) {
                        shouldReinit = true;
                    }
                });
            }
        });
        if (shouldReinit || shouldProcessPending) {
            // debounce: wait for 100ms
            clearTimeout(window.popoverReinitTimeout);
            window.popoverReinitTimeout = setTimeout(() => {
                initializeTriggers();
                processPendingContexts();
            }, 100);
        }
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true
    });

    // Fallback: if no mutation arrives (e.g. target added without a
    // childList event in the observed subtree), do one final check after the
    // timeout and then stop retrying.
    window.__subcontextPendingStop = setTimeout(() => {
        processPendingContexts();
        pendingExpired = true;
        pendingContexts.length = 0;
    }, PENDING_TIMEOUT_MS);

    // initial setup
    setupDelegation();
    initializeTriggers();
    processPendingContexts(); // safety net in case the target is already present

    // cleanup function
    return {
        dispose: () => {
            observer.disconnect();
            clearTimeout(window.popoverReinitTimeout);
            clearTimeout(window.__subcontextPendingStop);
            pendingExpired = true;
            pendingContexts.length = 0;
            document.querySelectorAll('.popover-trigger').forEach(trigger => {
                const popover = activePopovers.get(trigger);
                if (popover) popover.dispose();
                activePopovers.delete(trigger);
            });
            popoverTimeouts.clear();
        }
    };
};
