import { createSafeFilter } from "./wthb-filter.js";
import { getHelpTitle, setWthbLinkClickHandler } from "./wthb-logic.js";

export const insertSubcontextLinks = (
    document,
    window,
    bootstrap,
    jQuery,
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
        const target = (cfg.openInNewTab ? 'target="_blank" ' : '');
        const helptitle = getHelpTitle(url, cfg);
        const wthblink = jQuery(`<a href="${url}" ${target}class="stretched-link d-inline-block p-1 text-decoration-none"><i class="fa-solid fa-circle-question"></i> ${helptitle}</a>`);

        setWthbLinkClickHandler(document, window, bootstrap, jQuery, cfg, wthblink);

        const popover = newPopover(trigger, {
            placement: pos,
            content: wthblink[0]
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

    contexts.forEach((elem, index) => {
        let ctx = elem.ctx.trim();
        let url = elem.url;
        if (!ctx || !url) return;

        let node = null;
        let pos = "top";

        if (ctx.startsWith("{")) { // JSON object: {f:filter, e:JS, p:position} e or f needed, p optional
            try {
                let ctxobj = JSON.parse(ctx);
                if (ctxobj?.e ?? null) {
                    const filterFn = createSafeFilter(document, ctxobj.e);
                    const result = filterFn();
                    node = jQuery(Array.isArray(result) ? result[0] : result);
                } else {
                    node = jQuery((ctxobj?.f ?? null));
                }
                pos = ctxobj?.p ?? pos;
            } catch (e) {
                console.warn('LE-mod wthb subcontext:', ctx, e);
                return;
            }
        } else { // must be a filter expression
            node = jQuery(ctx);
        }

        if (jQuery.isEmptyObject(node) || node.length === 0) {
            return;
        }

        let poptrigger = jQuery('<span>', {
            class: 'popover-trigger',
            'data-subcontext': JSON.stringify({ url, pos }),
            text: 'ⓘ',
        });
        node.append(poptrigger);

        createPopoverForTrigger(poptrigger[0], url, pos);
    });

    // mutation observer
    const observer = new MutationObserver((mutations) => {
        let shouldReinit = false;
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
        if (shouldReinit) {
            // debounce: wait for 100ms
            clearTimeout(window.popoverReinitTimeout);
            window.popoverReinitTimeout = setTimeout(initializeTriggers, 100);
        }
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true
    });

    // initial setup
    setupDelegation();
    initializeTriggers();

    // cleanup function
    return {
        dispose: () => {
            observer.disconnect();
            document.querySelectorAll('.popover-trigger').forEach(trigger => {
                const popover = activePopovers.get(trigger);
                if (popover) popover.dispose();
                activePopovers.delete(trigger);
            });
            popoverTimeouts.clear();
        }
    };
};