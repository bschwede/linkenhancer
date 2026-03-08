import { expect } from "chai";
import { JSDOM } from "jsdom";
import { createSafeFilter }
    from "../src/wthb-filter.js";

describe("wthb-filter.js", () => {

    let document;

    beforeEach(() => {

        const dom = new JSDOM(`
        <div id="a"></div>
        <div class="b"></div>
        `);

        document = dom.window.document;
    });

    it("runs selector expression", () => {

        const fn =
            createSafeFilter(document,
                `querySelectorAll(".b")`);

        const r = fn();

        expect(r.length).to.equal(1);
    });

    it("returns null on error", () => {

        const fn =
            createSafeFilter(document,
                `invalid(`);


        expect(fn).to.equal(null);
    });

});