import { expect } from "chai";
import {
    isWthbLink,
    getHelpTitle,
    buildTranslateUrl
} from "../src/wthb-logic.js";

describe("wthb-logic.js", () => {

    const cfg = {
        wiki_url: "https://wiki.genealogy.net/",
        I18N: {
            help_title_wthb: "Manual",
            help_title_ext: "Help"
        }
    };

    it("detects wiki links", () => {

        const r = isWthbLink(
            "https://wiki.genealogy.net/Page",
            cfg.wiki_url
        );

        expect(r).to.equal(true);
    });

    it("detects external links", () => {

        const r = isWthbLink(
            "https://example.com",
            cfg.wiki_url
        );

        expect(r).to.equal(false);
    });

    it("returns help title for wiki", () => {

        const r = getHelpTitle(
            "https://wiki.genealogy.net/Page",
            cfg
        );

        expect(r).to.equal("Manual");
    });

    it("returns help title for external", () => {

        const r = getHelpTitle(
            "https://example.com",
            cfg
        );

        expect(r).to.equal("Help");
    });

    it("builds translate url", () => {

        const url = buildTranslateUrl(
            "https://wiki.genealogy.net/Test",
            "en"
        );

        expect(url).to.include("translate.google.com");
        expect(url).to.include("tl=en");
    });

});