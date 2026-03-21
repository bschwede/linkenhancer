import { expect } from "chai";
import { JSDOM } from "jsdom";
import {
    buildMenuHtml,
    insertMenu
} from "../src/wthb-menu.js";

describe("wthb-menu.js", () => {

    let document;

    const cfg = {
        help_url: "https://example.com",
        wiki_url: "https://wiki.genealogy.net/",
        faicon: false,
        openInNewTab: true,
        I18N: {
            help_title_ext: "Help",
            help_title_wthb: "Manual",
            startpage: "start page"
        },
        wthb_url: "https://wiki.genealogy.net",
        tocnsearch: false,
        wtcorehelp: false,
        admin_url: 'https://site/admin'
    };

    beforeEach(() => {

        const dom = new JSDOM(`
        <ul class="wt-user-menu"></ul>
        `);

        document = dom.window.document;
    });

    it("builds menu html", () => {

        const html = buildMenuHtml(cfg, "https://site");

        expect(html).to.include("wthb-link");
    });

    it("inserts menu into DOM", () => {

        const html = buildMenuHtml(cfg, "https://site");

        insertMenu(document, html);

        const link = document.querySelector("#wthb-link");

        expect(link).to.not.equal(null);
    });

    it("does not insert twice", () => {

        const html = buildMenuHtml(cfg, "https://site");

        insertMenu(document, html);
        insertMenu(document, html);

        const items = document.querySelectorAll("li.menu-wthb");

        expect(items.length).to.equal(1);
    });

    it("inserts admin menu item on non admin url", () => {

        const html = buildMenuHtml(cfg, "https://site");

        insertMenu(document, html);

        const link = document.querySelector(`[href="${cfg.admin_url}"]`);

        expect(link).to.not.equal(null);
    });

    it("inserts NO admin menu item on admin url", () => {
        const html = buildMenuHtml(cfg, cfg.admin_url);

        insertMenu(document, html);

        const link = document.querySelector(`[href="${cfg.admin_url}"]`);

        expect(link).to.equal(null);
    });

    it("inserts config menu item with lang != de and dotranslate == 1", () => {
        cfg.lang = 'en';
        cfg.dotranslate = 1;

        const html = buildMenuHtml(cfg, "https://site");

        insertMenu(document, html);

        const link = document.querySelector(`#wthb-link-cfg`);

        expect(link).to.not.equal(null);
    });    

    it("inserts NO config menuitem with lang == de and dotranslate == 1", () => {
        cfg.lang = 'de';
        cfg.dotranslate = 1;

        const html = buildMenuHtml(cfg, "https://site");

        insertMenu(document, html);

        const link = document.querySelector(`#wthb-link-cfg`);

        expect(link).to.equal(null);
    });

    it("normally inserts NO config menuitem", () => {
        const html = buildMenuHtml(cfg, "https://site");

        insertMenu(document, html);

        const link = document.querySelector(`#wthb-link-cfg`);

        expect(link).to.equal(null);
    });

    it("inserts additional external link", () => {
        cfg.linksJson = JSON.stringify([
            {
                title: 'External Link',
                url: 'https://external'
            }
        ]);
        const html = buildMenuHtml(cfg, "https://site");

        insertMenu(document, html);

        const link = document.querySelector(`.menu-wthb-external`);

        expect(link).to.not.equal(null);
    });

    
    it("inserts wt core help menu item", () => {
        cfg.wtcorehelp = true;

        const html = buildMenuHtml(cfg, "https://site");

        insertMenu(document, html);

        const link = document.querySelector(`#wthb-link-core`);

        expect(link).to.not.equal(null);
    });

    it("inserts wiki start page if help url is external", () => {
        const html = buildMenuHtml(cfg, "https://site");

        insertMenu(document, html);

        const link = document.querySelector(`#wthb-link-sp`);

        expect(link).to.not.equal(null);
    });

    it.skip("inserts NO wiki start page if help url is wiki page", () => {
        //?? SecurityError: localStorage is not available for opaque origins
        const html = buildMenuHtml(cfg, cfg.wiki_url);

        insertMenu(document, html);

        const link = document.querySelector(`#wthb-link-sp`);

        expect(link).to.equal(null);
    });    
});