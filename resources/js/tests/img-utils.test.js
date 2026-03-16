import { expect } from 'chai'
import { JSDOM } from 'jsdom'

import {
    addClickIfNone,
    gotoTop
} from '../src/img-utils.js'


describe('md-utils', () => {

    let dom
    let document
    let window

    beforeEach(() => {

        dom = new JSDOM(`
            <body>
                <button id="btn"></button>
                <div id="scroll">
                    <div style="height:1000px"></div>
                </div>
            </body>
        `)

        document = dom.window.document
        window = dom.window
    })


    it('addClickIfNone should attach only one handler', () => {

        const btn = document.getElementById('btn')

        let count = 0

        const handler = () => count++

        addClickIfNone(btn, handler)
        addClickIfNone(btn, handler)

        btn.click()

        expect(count).to.equal(1)
    })


    it('gotoTop without ref scrolls window', () => {

        let called = false

        window.scrollTo = () => called = true

        gotoTop(window, document)

        expect(called).to.equal(true)
    })

})