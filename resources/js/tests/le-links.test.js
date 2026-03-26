import { expect } from "chai"
import { JSDOM } from "jsdom"

import { getLETargetCfg, getLEOptions } from '../src/le-config.js';

import { getLErecTypes }
    from "../src/le-xref-parser.js"

import { processLinks }
    from "../src/le-link-processor.js"


describe("external link types", () => {

    const options = Object.assign(getLEOptions() , {
        I18N: {},
        openInNewTab: true,
        baseurl: "https://example.org/tree/main",
        tree: "main",
        urlmode: "pretty"
    })
    const cfg = getLETargetCfg(options, getLErecTypes);

    const linkCases = [
        //expected: "about:blank" if it fails
        {
            key: "wp",
            param: "de/Webtrees",
            expected: "de.wikipedia.org"
        },
        {
            key: "wp",
            param: "Domain-missing",
            expected: "about:blank"
        },
        {
            key: "wp",
            param: "en/Topic/Subpage",
            expected: "en.wikipedia.org"
        },

        {
            key: "ofb",
            param: "dornbirn/BDDBBFB7531C4A2EB0F56852BDD7720F8697A5",
            expected: "ofb.genealogy.net/famreport.php?ofb="
        },        
        {
            key: "ofb",
            param: "dornbirn-missing-second-param",
            expected: "about:blank"
        },
        {
            key: "ofb",
            param: "dornbirn/too-many/params",
            expected: "about:blank"
        },

        {
            key: "gw",
            param: "Berlin",
            expected: "wiki.genealogy.net"
        },

        {
            key: "gov",
            param: "BUDGENJO40NG",
            expected: "gov.genealogy.net"
        },

        {
            key: "gedbas",
            param: "1051362866",
            expected: "https://gedbas.genealogy.net/person/show/"
        },
        {
            key: "gedbas",
            param: "56789/136049f257c96e34430aec053fa0fbce865c",
            expected: "https://gedbas.genealogy.net/person/uid/"
        },
        {
            key: "gedbas",
            param: "b43cd5ad-695c-4e1f-9c9d-25918d292256",
            expected: "https://gedbas.genealogy.net/uid/"
        },
        {
            key: "gedbas",
            param: "123/456/789", // too many slashes
            expected: "about:blank"
        },
        {
            key: "gedbas",
            param: "wrongchars", // wrong characters
            expected: "about:blank"
        },

        {
            key: "ewp",
            param: "I100",
            expected: "westpreussen.de/tngeinwohner/getperson.php"
        },

        {
            key: "osm",
            param: "17/53.619095/10.037395",
            expected: "www.openstreetmap.org/#map"
        },
        {
            key: "osm",
            param: "17/53.619095/10.037395/!",
            expected: "www.openstreetmap.org/?mlat=53.619095&mlon=10.037395#map"
        },
        {
            key: "osm",
            param: "17/53.619095",
            expected: "about:blank"
        },
        {
            key: "osm",
            param: "13/50.09906/8.04660/?relation=403139",
            expected: "www.openstreetmap.org/?relation=403139#map"
        },

        {
            key: "wit",
            param: "Smith-123",
            expected: "www.wikitree.com"
        },

        {
            key: "vl",
            param: "123456789",
            expected: "https://des.genealogy.net/search/uuid/"
        },   
        
    ]

    linkCases.forEach(testcase => {
        it(`${testcase.expected === "about:blank" ? 'fail' : 'link'} ${testcase.key}=${testcase.param}`, () => {

            const dom = new JSDOM(`
        <body>
          <a id="link"
             href="#@${testcase.key}=${testcase.param}">
             link
          </a>
        </body>`)

            const document = dom.window.document

            processLinks(document, cfg, options)

            const link =
                document.querySelector("#link")

            //console.log(link.href)
                expect(link.href)
                .to.contain(testcase.expected)

        })

    })

})