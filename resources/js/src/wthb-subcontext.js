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

    const popovers = [];

    const target = (cfg.openInNewTab ? 'target="_blank" ' : '');
    contexts.forEach((elem) => {
        let ctx = elem.ctx;
        let url = elem.url;
        if (!ctx || !url) return;

        let node = null;
        let pos = "top";
        ctx = ctx.trim();
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
            text: 'ⓘ',
        });
        node.append(poptrigger);

        let helptitle = getHelpTitle(url, cfg);
        let wthblink = jQuery(`<a href="${url}" ${target}class="stretched-link d-inline-block p-1 text-decoration-none"><i class="fa-solid fa-circle-question"></i> ${helptitle}</a>`);
        setWthbLinkClickHandler(document, window, bootstrap, jQuery, cfg, wthblink);
        popovers.push(newPopover(poptrigger, {
            placement: pos,
            content: wthblink
        })); // poptrigger.popover() doesn`t work
    });

    popovers.forEach(el => {
        el._element.addEventListener('show.bs.popover', () => {
            // Disable popovers within group, except the current one
            popovers.forEach(otherEl => {
                if (otherEl !== el) {
                    otherEl.hide();
                }
            });
        });

        el._element.addEventListener('shown.bs.popover', () => {
            // Automatic fading after 5 seconds
            onShownBsPopoverHideTimeout(el);
        });

        el._element.addEventListener('hide.bs.popover', () => {
            // Delete timer if closed manually
            onHideBsPopoverHideTimout(el);
        });
    });
};

const onShownBsPopoverHideTimeout = (el, timersec = 5000) => {
    // Automatic fading after timersec seconds
    if (el.hideTimeout) clearTimeout(el.hideTimeout);
    el.hideTimeout = setTimeout(() => {
        el.hide();
    }, timersec);
}
const onHideBsPopoverHideTimout = (el) => {
    // Delete timer if closed manually
    if (el.hideTimeout) {
        clearTimeout(el.hideTimeout);
        el.hideTimeout = null;
    }
}