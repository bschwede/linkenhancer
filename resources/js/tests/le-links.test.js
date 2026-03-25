import { expect } from "chai"
import { JSDOM } from "jsdom"

import { i18nMixin } from './i18n-mixin.js';

import { processLinks }
    from "../src/le-link-processor.js"

describe("multiple link types", () => {

    const linkCases = [

        {
            key: "wp",
            param: "Webtrees",
            expected: "wikipedia"
        },

        {
            key: "gw",
            param: "Berlin",
            expected: "genealogy.net"
        },

        {
            key: "wit",
            param: "Smith-123",
            expected: "wikitree"
        }

    ]


    linkCases.forEach(testcase => {

        it(`creates ${testcase.key} link`, () => {

            const dom = new JSDOM(`
        <body>
          <a id="link"
             href="#@${testcase.key}=${testcase.param}">
             link
          </a>
        </body>
      `)

            const document = dom.window.document

            processLinks(document, cfg, options)

            const link =
                document.querySelector("#link")

            expect(link.href)
                .to.contain(testcase.expected)

        })

    })

})