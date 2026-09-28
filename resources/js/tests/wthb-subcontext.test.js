import { expect } from "chai";
import { JSDOM } from "jsdom";
import { insertSubcontextLinks } from "../src/wthb-subcontext.js";
import { i18nMixin } from '../src/i18n-mixin.js';

describe("wthb-subcontext.js", () => {

    // Minimal bootstrap.Popover stub - the popover content is not under test.
    const mockBootstrap = {
        Popover: class {
            constructor() {}
            show() {}
            hide() {}
            dispose() {}
        }
    };

    const delay = (ms) => new Promise(resolve => setTimeout(resolve, ms));

    // Poll until fn() is truthy (or the timeout elapses) - tolerant to the
    // async MutationObserver delivery + 100ms debounce in the module.
    const waitFor = async (fn, timeout = 2000, interval = 25) => {
        const start = Date.now();
        while (Date.now() - start < timeout) {
            if (fn()) return true;
            await delay(interval);
        }
        return fn();
    };

    let dom;
    let document;
    let window;
    let result;
    let savedMO;
    let savedNode;

    const cfg = {
        ...i18nMixin,
        I18N: {
            help_title_wthb: "Manual",
            help_title_ext: "Help"
        },
        wiki_url: "https://wiki.genealogy.net",
        wthb_url: "https://wiki.genealogy.net/Webtrees_Handbuch",
        openInNewTab: true,
        subcontext: []
    };

    beforeEach(() => {
        dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', { url: 'http://localhost' });
        window = dom.window;
        document = window.document;

        // insertSubcontextLinks references the global MutationObserver/Node;
        // point them at this JSDOM realm so the observer sees the local DOM.
        savedMO = globalThis.MutationObserver;
        savedNode = globalThis.Node;
        globalThis.MutationObserver = window.MutationObserver;
        globalThis.Node = window.Node;
    });

    afterEach(() => {
        result?.dispose();
        result = null;
        globalThis.MutationObserver = savedMO;
        globalThis.Node = savedNode;
    });

    it("attaches the trigger immediately when the target already exists", () => {
        document.body.innerHTML = '<div id="c"><a href="#_linkenhancer_">Tab</a></div>';

        cfg.subcontext = [{ ctx: 'a[href="#_linkenhancer_"]', url: 'https://wiki/page' }];
        result = insertSubcontextLinks(document, window, mockBootstrap, cfg);

        const trigger = document.querySelector('.popover-trigger');
        expect(trigger).to.not.equal(null);
        expect(trigger.parentElement.getAttribute('href')).to.equal('#_linkenhancer_');
    });

    it("retries and attaches the trigger for a target that is added later (dynamic tab)", async () => {
        document.body.innerHTML = '<div id="c"></div>';

        cfg.subcontext = [{ ctx: 'a[href="#_linkenhancer_"]', url: 'https://wiki/page' }];
        result = insertSubcontextLinks(document, window, mockBootstrap, cfg);

        // not present yet
        expect(document.querySelector('.popover-trigger')).to.equal(null);

        // simulate the JS-injected cross-reference tab
        const a = document.createElement('a');
        a.setAttribute('href', '#_linkenhancer_');
        a.textContent = 'Tab';
        document.getElementById('c').appendChild(a);

        const appeared = await waitFor(() => !!document.querySelector('.popover-trigger'));
        expect(appeared).to.equal(true);
        expect(document.querySelector('.popover-trigger').parentElement).to.equal(a);
    });

    it("stops retrying once the pending timeout has expired", async () => {
        document.body.innerHTML = '<div id="c"></div>';

        cfg.subcontext_pending_timeout = 50;
        cfg.subcontext = [{ ctx: 'a[href="#_linkenhancer_"]', url: 'https://wiki/page' }];
        result = insertSubcontextLinks(document, window, mockBootstrap, cfg);
        cfg.subcontext_pending_timeout = 5000;

        // let the retry window close
        await delay(150);

        // the target appears only after the window is closed
        const a = document.createElement('a');
        a.setAttribute('href', '#_linkenhancer_');
        a.textContent = 'Tab';
        document.getElementById('c').appendChild(a);
        await delay(250);

        expect(document.querySelector('.popover-trigger')).to.equal(null);
    });

    it("does not double-attach a trigger that is already present", () => {
        document.body.innerHTML =
            '<div id="c"><a href="#_linkenhancer_"><span class="popover-trigger">x</span></a></div>';

        cfg.subcontext = [{ ctx: 'a[href="#_linkenhancer_"]', url: 'https://wiki/page' }];
        result = insertSubcontextLinks(document, window, mockBootstrap, cfg);

        expect(document.querySelectorAll('.popover-trigger').length).to.equal(1);
    });

    it("does not retry a malformed JSON context", () => {
        document.body.innerHTML = '<div id="c"><a href="#_linkenhancer_">Tab</a></div>';

        cfg.subcontext = [{ ctx: '{not valid json', url: 'https://wiki/page' }];
        result = insertSubcontextLinks(document, window, mockBootstrap, cfg);

        expect(document.querySelector('.popover-trigger')).to.equal(null);
    });

    it("dispose clears the pending fallback timer (no late trigger)", async () => {
        document.body.innerHTML = '<div id="c"></div>';

        cfg.subcontext_pending_timeout = 30;
        cfg.subcontext = [{ ctx: 'a[href="#_linkenhancer_"]', url: 'https://wiki/page' }];
        result = insertSubcontextLinks(document, window, mockBootstrap, cfg);
        cfg.subcontext_pending_timeout = 5000;

        result.dispose();

        // well past where the fallback timer would have fired
        await delay(120);
        expect(document.querySelector('.popover-trigger')).to.equal(null);
    });
});
