import { getOptions } from './img-config.js';

import {
    MD_SECTION_SELECTOR
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

import {
    initChildListObserver,
    initTdHObserver
} from './img-observers.js';


export const initMdExt = (
    document,
    window,
    options
) => {

    const opts =
        (typeof options === 'object' && options !== null)
            ? Object.assign(getOptions(), options)
            : getOptions();


    const initGotoTopHandler = (root = document) => { // goto top of cell (with toc in dropdown)

        root
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
                node,
                'fn_',
                MD_SECTION_SELECTOR
            );

            uniqueRefs(
                node,
                'fnref_',
                MD_SECTION_SELECTOR
            );
        }

        if (opts.ext_toc) {

            uniqueRefs(
                node,
                'mdnote-',
                MD_SECTION_SELECTOR
            );

            initGotoTopHandler(node);
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


    processNode(document);


    initChildListObserver(
        document,
        processNode
    );


    if (opts.td_h_ctrl) {

        initTdHObserver(
            document,
            checkAndTriggerCheckboxes
        );
    }
};