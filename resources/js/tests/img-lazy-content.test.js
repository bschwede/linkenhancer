import { expect } from 'chai'
import sinon from 'sinon'
import { JSDOM } from 'jsdom'

import { initMdExt }
    from '../src/img.js'


describe('Lazy loading behaviour', () => {

    let dom
    let document
    let window
    let clock

    global.MutationObserver = class {
        constructor(callback) { }
        disconnect() { }
        observe(element, initObject) { }
    };

    beforeEach(() => {

        dom = new JSDOM(`
            <body>
                <section class="md-content"></section>
            </body>
        `, { pretendToBeVisual: true })

        window = dom.window
        document = window.document

        global.requestAnimationFrame =
            cb => setTimeout(cb, 0)

        clock = sinon.useFakeTimers()
    })


    afterEach(() => {
        clock.restore()
    })


    it('should process newly inserted markdown sections', () => {

        initMdExt(document, window, {})

        const section = document.createElement('section')
        section.className = 'md-content'

        document.body.appendChild(section)

        clock.tick(20)

        expect(section).to.exist
    })


    it('should process duplicated footnote ids from lazy content', () => {

        initMdExt(document, window, {})

        const container =
            document.querySelector('.md-content')

        container.innerHTML = `
            <div id="fn_1"></div>
            <div id="fn_1"></div>
        `

        clock.tick(20)

        const ids =
            [...document.querySelectorAll('[id^="fn_"]')]
                .map(el => el.id)

        expect(new Set(ids).size)
            .to.equal(ids.length)
    })


    it('should attach gotoTop handlers on lazy nodes', () => {

        initMdExt(document, window, {})

        const btn =
            document.createElement('button')

        btn.className = 'gototop'

        document.body.appendChild(btn)

        clock.tick(20)

        expect(
            btn.getAttribute('data-click-listener')
        ).to.equal('true')
    })

})