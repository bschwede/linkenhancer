import { expect } from "chai"
import { JSDOM } from "jsdom"

import { i18nMixin } from './i18n-mixin.js';

import { parseCrossReferenceLink, getLErecTypes }
    from "../src/le-xref-parser.js"

import { processLinks }
    from "../src/le-link-processor.js"


describe("Enhanced Links core tests", () => {

    let document
    let window

    const cfg = {
        wp: {
            name: "Wikipedia",
            url: "https://wikipedia.org/wiki/",
            cname: "icon-wp"
        }
    }

    const options = {
        ...i18nMixin,
        openInNewTab: true,
        baseurl: "https://example.org/tree/main",
        tree: "main",
        urlmode: "pretty"
    }


    beforeEach(() => {

        const dom = new JSDOM(`
      <body>
        <section class="md-content">

          <a id="wp"
             href="#@wp=Webtrees">
             wiki
          </a>

        </section>
      </body>
    `)

        window = dom.window
        document = window.document
    })


    /* ---------------------------
       XREF PARSER
    --------------------------- */

    it("parses cross reference", () => {

        const rectypes =
            getLErecTypes()

        const res =
            parseCrossReferenceLink(
                "i@I123@tree",
                rectypes
            )

        expect(res.xref).to.equal("I123")
        expect(res.type).to.equal("i")

    })


    it("detects diagram flag", () => {

        const rectypes =
            getLErecTypes()

        const res =
            parseCrossReferenceLink(
                "i@I123@ dia",
                rectypes
            )

        expect(res.dia).to.equal(true)

    })


    /* ---------------------------
       LINK PROCESSING
    --------------------------- */

    it("creates wikipedia link", () => {

        processLinks(document, cfg, options)

        const link =
            document.querySelector("#wp")

        expect(link.href)
            .to.contain("wikipedia")

    })


    it("adds icon element", () => {

        processLinks(document, cfg, options)

        const icon =
            document.querySelector(".icon-wp")

        expect(icon).to.exist

    })


    it("handles unknown parameters", () => {

        const dom = new JSDOM(`
      <body>
        <a id="bad"
           href="#@foo=bar">
           x
        </a>
      </body>
    `)

        const document = dom.window.document

        processLinks(document, cfg, options)

        const link =
            document.querySelector("#bad")

        expect(link.title)
            .to.contain("param error")

    })


    /* ---------------------------
       WEAKSET CACHE
    --------------------------- */

    it("does not process link twice", () => {

        processLinks(document, cfg, options)
        processLinks(document, cfg, options)

        const links =
            document.querySelectorAll("#wp")

        expect(links.length).to.equal(1)

    })


    /* ---------------------------
       CONTAINER PROCESSING
    --------------------------- */

    it("processes links inside container", () => {

        const dom = new JSDOM(`
      <body>
        <section class="md-content">
          <a href="#@wp=JavaScript">x</a>
        </section>
      </body>
    `)

        const document = dom.window.document

        processLinks(document, cfg, options)

        const link =
            document.querySelector("a")

        expect(link.href)
            .to.contain("wikipedia")

    })

})