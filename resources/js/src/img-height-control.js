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


const createHeightCheckbox = (
    document,
    opts
) => { // DOM creation

    const checked =
        (opts.td_h_ctrl === 2) ? ' checked' : '';

    const cbvis =
        (opts.td_h_cb ? '' : ' invisible');

    const title =
        escapeHtmlAttribute(
            document,
            opts.I18N.limitheight ?? ''
        );

    return `
        <div class="md-sticky-wrapper me-0 float-end${cbvis}">
            <input
                class="td-h-checker"
                type="checkbox"
                title="${title}"${checked}>
        </div>
    `;
};


const evaluateHeightLimit = (
    element,
    cssMaxHeight,
    window
) => {

    const height = element.offsetHeight;

    return (
        height >
        parseCssValue(cssMaxHeight, window.innerHeight)
    );
};



const attachHeightHandler = (
    checkbox,
    cssMaxHeight,
    window,
    document
) => { // Event handling

    checkbox.addEventListener('change', function () {

        const target =
            this.closest('tr').children[1];

        if (this.checked) {

            if (
                target.classList.contains(TD_H_CLASS)
            ) return;

            const worthIt =
                evaluateHeightLimit(
                    target,
                    cssMaxHeight,
                    window
                );

            if (!worthIt) {

                this.checked =
                    (target.offsetHeight === 0);

                return;
            }

            target.classList.add(TD_H_CLASS);

        } else {

            target.classList.remove(TD_H_CLASS);
        }


        if (this.hasAttribute('data-triggered')) {

            gotoTop(
                window,
                document,
                this.closest('tr').children[1]
            );
        }
    });
};


export const initTdHCtrl = (
    document,
    window,
    opts
) => { // table cell height control - init routine

    const checkboxHtml =
        createHeightCheckbox(document, opts);

    const cssMaxHeight =
        findCssRule(
            document,
            `.${TD_H_CLASS}`,
            'max-height'
        );

    const targets = [...new Set(

        Array
            .from(
                document.querySelectorAll(
                    MD_SECTION_SELECTOR
                )
            )
            .map(el => el.closest('td'))
            .filter(Boolean)

    )];

    targets.forEach(targetTd => {

        const firstTd =
            targetTd.parentElement.children[0];

        if (
            firstTd.querySelector(TD_H_SELECTOR)
        ) return;

        firstTd.insertAdjacentHTML(
            'afterbegin',
            checkboxHtml
        );

        const checkbox =
            firstTd.querySelector(TD_H_SELECTOR);

        attachHeightHandler(
            checkbox,
            cssMaxHeight,
            window,
            document
        );

        checkbox.dispatchEvent(
            new Event('change')
        );
    });
};


export const checkAndTriggerCheckboxes = node => { // Visibility trigger logic

    if (!(node instanceof Element)) return;

    if (
        node.checkVisibility?.() ||
        node.offsetParent !== null
    ) {

        const checkboxes =
            node.querySelectorAll(TD_H_SELECTOR);

        checkboxes.forEach(cb => {

            if (!cb.hasAttribute('data-triggered')) {

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

            } else {

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