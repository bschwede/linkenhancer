import { getOptions } from './img-config.js';

import {
    MD_SECTION_SELECTOR,
    OBSERVED_TAGS
} from './img-consts.js';

import {
    addClickIfNone,
    gotoTop
} from './img-utils.js';

import { uniqueRefs }
    from './img-unique-refs.js';

import {
    initTdHCtrl,
    checkAndTriggerCheckboxes
} from './img-height-control.js';

import { createDomObserver } from './dom-observer-factory.js';

export const initMdExt = (
    document,
    window,
    options
) => {

    const opts =
        (typeof options === 'object' && options !== null)
            ? Object.assign(getOptions(), options)
            : getOptions();


    const initGotoTopHandler = () => { // goto top of cell (with toc in dropdown)

        document
            .querySelectorAll("button.gototop")
            .forEach(el => {

                addClickIfNone(el, e => {

                    gotoTop(
                        window,
                        document,
                        e.target.closest("td")
                    );
                });
            });
    };


    const processNode = node => {

        if (opts.ext_fn) {

            uniqueRefs(
                document, // node, always apply to the entire document
                'fn_',
                MD_SECTION_SELECTOR
            );

            uniqueRefs(
                document, //node, always apply to the entire document
                'fnref_',
                MD_SECTION_SELECTOR
            );
        }

        if (opts.ext_toc) {

            uniqueRefs(
                document, // node, always apply to the entire document
                'mdnote-',
                MD_SECTION_SELECTOR
            );

            initGotoTopHandler();
        }

        if (opts.td_h_ctrl) {

            initTdHCtrl(
                document,
                window,
                opts
            );

            checkAndTriggerCheckboxes(node);
        }
    };

    // childList - added elements
    createDomObserver({

        root: document,

        match: node => OBSERVED_TAGS.includes(node.tagName),

        process: processNode,

        initialScan: true
    })


    if (opts.td_h_ctrl) {

        // attribute changes
        createDomObserver({

            root: document,

            process: checkAndTriggerCheckboxes,

            attributeFilter: ['style', 'class'],

        })        
    }
};