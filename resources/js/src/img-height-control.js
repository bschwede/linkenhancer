import {
    MD_SECTION_SELECTOR,
    TD_H_SELECTOR,
    TD_H_CLASS
} from './img-consts.js';

import {
    findCssRule,
    parseCssValue
} from './img-css.js';

import {
    escapeHtmlAttribute,
    gotoTop
} from './img-utils.js';


export const initTdHCtrl = (
    document,
    window,
    opts
) => { // table cell height control - init routine

    const checked =
        (opts.td_h_ctrl === 2) ? ' checked' : '';

    const cbvis =
        (opts.td_h_cb ? '' : ' invisible');

    const title =
        escapeHtmlAttribute(
            document,
            opts.I18N.limitheight ?? ''
        );

    const html =
        `<div class="md-sticky-wrapper me-0 float-end${cbvis}">
        <input class="td-h-checker"
        type="checkbox"
        title="${title}"${checked}>
        </div>`;

    const targets = [...new Set(

        Array
            .from(
                document.querySelectorAll(
                    MD_SECTION_SELECTOR
                )
            )
            .map(el => el.closest('td'))
            .filter(Boolean) // remove null and undefined values from an array - https://mikebifulco.com/posts/javascript-filter-boolean

    )];

    targets.forEach(targetTd => {
        // 2-column tables: first td contains cehckbox, second td is controlled
        const firstTd =
            targetTd.parentElement.children[0];

        if (
            firstTd.querySelector(TD_H_SELECTOR)
        ) return;

        firstTd.insertAdjacentHTML(
            'afterbegin',
            html
        );

        const cssMaxHeight =
            findCssRule(
                document,
                `.${TD_H_CLASS}`,
                'max-height'
            ); // keep in sync with css rule

        const checkbox =
            firstTd.querySelector(TD_H_SELECTOR);

        checkbox.addEventListener(
            'change',
            function () {

                const target =
                    this.closest('tr').children[1];

                if (this.checked) {

                    if (
                        target.classList.contains(
                            TD_H_CLASS
                        )
                    ) return; // it's already under control

                    const tdHeight =
                        target.offsetHeight;

                    const isWorthIt =
                        tdHeight >
                        parseCssValue(
                            cssMaxHeight,
                            window.innerHeight
                        );

                    if (!isWorthIt) {

                        this.checked =
                            (tdHeight === 0); // if element not visible

                        return;
                    }

                    target.classList.add(
                        TD_H_CLASS
                    );

                } else {

                    target.classList.remove(
                        TD_H_CLASS
                    );
                }

                if (
                    this.hasAttribute(
                        'data-triggered'
                    )
                ) { // no scrolling on init

                    gotoTop(
                        window,
                        document,
                        this.closest('tr').children[1] // always start with content top
                    );
                }
            }
        );

        checkbox.dispatchEvent(
            new Event('change')
        );
    });
};


export const checkAndTriggerCheckboxes = node => { // cell height control - trigger all checkboxes in a node when visible

    if (!(node instanceof Element)) return;

    // checkVisibility() for real visibility (opacity, display, etc.)
    if (node.checkVisibility?.() || node.offsetParent !== null) {

        const checkboxes =
            node.querySelectorAll(TD_H_SELECTOR);

        checkboxes.forEach(cb => {

            if (!cb.hasAttribute('data-triggered')) {

                cb.dispatchEvent(
                    new Event('change', { bubbles: true })
                );

                cb.setAttribute(
                    'data-triggered',
                    'true'
                ); // only once per visibility cycle

            } else { // for cases were init doesn't work properly (INDI page > note tab > show all notes)

                const target =
                    cb.closest('tr').children[1];

                if (
                    cb.checked &&
                    !target.classList.contains(
                        TD_H_CLASS
                    )
                ) {

                    cb.removeAttribute(
                        'data-triggered'
                    );

                    cb.dispatchEvent(
                        new Event(
                            'change',
                            { bubbles: true }
                        )
                    );

                    cb.setAttribute(
                        'data-triggered',
                        'true'
                    );
                }
            }
        });
    }
};
