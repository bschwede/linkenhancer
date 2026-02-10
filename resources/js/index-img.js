// Markdown Enhancements
const getOptions = () => {
    return {
        I18N: {
            limitheight: 'Limit cell height',
        },
        ext_fn: true,  // Footnotes extension
        ext_toc: true, // Table of contents extension
        // tabel cell height control
        td_h_ctrl: 1, // triple-state: 0=off, 1=checkbox visible, 2=checkbox visible and checked
        td_h_cb: true,
    }
}
let OPTS = getOptions();

const mdsection_selector = 'section.md-content';

const td_h_selector = 'input.td-h-checker[type="checkbox"]';
const td_h_class = 'md-td-heightctrl';

const initGototopHandler = () => {
    // goto top of cell (with toc in dropdown)
    [...document.querySelectorAll("button.gototop")].forEach((el) => {
        addClickIfNone(el, (e) => {
            gotoTop(e.target.closest("td"));
        });
    });
}

const addClickIfNone = (el, handler) => { // only add one click handler
    if (el.getAttribute('data-click-listener') === 'true') return;
    el.setAttribute('data-click-listener', 'true');
    el.addEventListener('click', handler);
}

const escapeHtmlAttribute = (str) => {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

const findCssRule = (selector, property) => { // find a specific css rule and property
    for (const sheet of document.styleSheets) {
        try {
            for (const rule of sheet.cssRules) {
                if (rule instanceof CSSStyleRule && rule.selectorText === selector) {
                    return rule.style.getPropertyValue(property);
                }
            }
        } catch (e) {
            // ignore CORS errors for external stylesheets
            continue;
        }
    }
    return null;
}


const parseCssValue = (cssValue, relativeToVh = window.innerHeight) => { // cell height control - retreive height value as float
    if (cssValue.includes('vh')) {
        return parseFloat(cssValue) / 100 * relativeToVh;
    }
    if (cssValue.includes('px')) {
        return parseFloat(cssValue);
    }
    return Infinity; // none, auto, etc.
}

const gotoTop = (refElement = null) => { // scroll to top of window or ref element
    if (!refElement) {
        window.scrollTo({ top: 0, left: 0, behavior: 'smooth' });
        return;
    }

    // refElement and children
    const walker = document.createTreeWalker(
        refElement,
        NodeFilter.SHOW_ELEMENT,
        {
            acceptNode(node) {
                const el = node;
                return (el.offsetParent !== null && el.getClientRects().length > 0)
                    ? NodeFilter.FILTER_ACCEPT
                    : NodeFilter.FILTER_SKIP;
            }
        }
    );

    const firstVisible = walker.nextNode();

    if (firstVisible) {
        firstVisible.scrollIntoView({
            behavior: 'smooth',
            block: 'start',
            inline: 'nearest'
        });
    } else {
        window.scrollTo({ top: 0, left: 0, behavior: 'smooth' });
    }
}

const getIdMaxIndexSuffix = (baseId) => { // helper for uniqueRefs - important for lazy loading content
    const elements = document.querySelectorAll(`[id^="${baseId}_"]`);

    const indices = Array.from(elements).map(el => {
        const id = el.id;
        const lastUnderscore = id.lastIndexOf('_');
        const suffix = id.substring(lastUnderscore + 1);
        return parseInt(suffix, 10);
    }).filter(n => !isNaN(n));

    return (indices.length > 0 ? Math.max(...indices) : 0);
}

const uniqueRefs = (idPrefix) => {
    // notes can be linked multiple on record pages - especially on the INDI page
    // so IDs aren't unique anymore and need to be corrected in order to make links work again
    const elems = document.querySelectorAll(`[id^="${idPrefix}"]`);
    const seen = {};

    elems.forEach(el => seen[el.id] = (seen[el.id] || 0) + 1 );
    //console.log('All IDs: ', seen);
    for (const [baseId, idCount] of Object.entries(seen)) {
        if (idCount > 1) {
            let elems = document.querySelectorAll(`[id="${baseId}"]`);
            for (let nthElem = 1; nthElem < idCount; nthElem++) {
                //it's easier now to detect md rendered content - before: let noteElem = elems[nthElem].closest('div.wt-fact-notes, td > div:not(.footnotes), td'); // div.wt-fact-notes - notes for names on INDI page
                let noteElem = elems[nthElem].closest(mdsection_selector); // see LinkEnhancerModule::STDCLASS_MD_CONTENT
                if (noteElem) {
                    let lastIdx = getIdMaxIndexSuffix(baseId); // if called by observer, there are already adjusted IDs
                    let newId = `${baseId}_${nthElem+lastIdx}`;
                    let newHref = `#${newId}`;
                    elems[nthElem].id = newId;
                    let refElems = noteElem.querySelectorAll(`a[href="#${baseId}"]`);
                    Array.from(refElems)
                        .forEach((a) => {
                            a.setAttribute('href', newHref);
                        });
                } else {
                    console.warn("LE-mod md refs - No note element found for: ", nthElem, baseId);
                }
            }
        }
    }
}

const initTdHCtrl = () => { // table cell height control - init routine
    const checked = (OPTS.td_h_ctrl === 2) ? ' checked' : '';
    const cbvis = (OPTS.td_h_cb ? '' : ' invisible');
    const title = escapeHtmlAttribute(OPTS.I18N.limitheight ?? '');
    const html = `<div class="md-sticky-wrapper me-0 float-end${cbvis}"><input class="td-h-checker" type="checkbox" title="${title}"${checked}></div>`;

    [...new Set(
        Array.from(document.querySelectorAll(mdsection_selector))
            .map(el => el.closest('td'))
            .filter(Boolean) // remove null and undefined values from an array - https://mikebifulco.com/posts/javascript-filter-boolean
    )].forEach(targetTd => {
        // 2-column tables: first td contains cehckbox, second td is controlled
        const firstTd = targetTd.parentElement.children[0];

        if (firstTd.querySelector(td_h_selector) !== null) {
            return;
        }

        firstTd.insertAdjacentHTML('afterbegin', html);
        
        const cssMaxHeight = findCssRule(`.${td_h_class}`, 'max-height'); // keep in sync with css rule

        // register event handler for checkboxes
        const checkbox = firstTd.querySelector(td_h_selector);
        checkbox.addEventListener('change', function () {
            const targetTdFromCheckbox = this.closest('tr').children[1];
            if (this.checked) {
                if (targetTdFromCheckbox.classList.contains(td_h_class)) { // it's already under control
                    return;
                }
                const tdHeight = targetTdFromCheckbox.offsetHeight;
                const isWorthIt = tdHeight > parseCssValue(cssMaxHeight);
                if (!isWorthIt) {
                    this.checked = (tdHeight === 0); // if element not visible
                    return;
                }
                targetTdFromCheckbox.classList.add(td_h_class);
            } else {
                targetTdFromCheckbox.classList.remove(td_h_class);
            }
            
            if (this.hasAttribute('data-triggered')) { // no scrolling on init
                gotoTop(this.closest('tr').children[1]); // always start with content top
            }
        });

        // init
        checkbox.dispatchEvent(new Event('change'));
    });    
}

const checkAndTriggerCheckboxes = (node) => { // cell height control - trigger all checkboxes in a node when visible
    if (!(node instanceof Element)) return;

    // checkVisibility() for real visibility (opacity, display, etc.)
    if (node.checkVisibility?.() || node.offsetParent !== null) {
        const checkboxes = node.querySelectorAll(td_h_selector);
        checkboxes.forEach(cb => {
            if (!cb.hasAttribute('data-triggered')) {
                cb.dispatchEvent(new Event('change', { bubbles: true }));
                cb.setAttribute('data-triggered', 'true'); // only once per visibility cycle
            } else { // for cases were init doesn't work properly (INDI page > note tab > show all notes)
                const targetTdFromCheckbox = cb.closest('tr').children[1];
                if (cb.checked && !targetTdFromCheckbox.classList.contains(td_h_class)) {
                    cb.removeAttribute('data-triggered');
                    cb.dispatchEvent(new Event('change', { bubbles: true }));
                    cb.setAttribute('data-triggered', 'true');
                }
            }
        });
    }
}

const initTdHObserver = () => {
    // cell height control - observer for visibility changes
    const visibilityObserver = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            if (mutation.type === 'attributes' &&
                (mutation.attributeName === 'style' ||
                    mutation.attributeName === 'class')) {

                // check each affected element + children
                mutation.addedNodes.forEach(node => checkAndTriggerCheckboxes(node));
                mutation.removedNodes.forEach(node => checkAndTriggerCheckboxes(node));

                // attribute changes to the targetNode itself
                checkAndTriggerCheckboxes(mutation.target);
            }
        });
    });

    visibilityObserver.observe(document.body, {
        attributes: true,
        subtree: true,
        attributeFilter: ['style', 'class'],
        attributeOldValue: false,
    });    
}

const initChildListObserver = () => {
    // global observer for lazy loading content
    const obsTags = ['DIV', 'SECTION'];
    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            if (mutation.type === 'childList' && obsTags.includes(mutation.target.tagName)) {
                if (OPTS.ext_fn ?? false) {
                    // footnotes - prefixes declared for CommonMark FootnoteExtension in Factories/CustomMarkdownFactory.php
                    uniqueRefs('fn_');
                    uniqueRefs('fnref_');
                }
                if (OPTS.ext_toc ?? false) {
                    // table of contents / heading permalink - prefix declared for CommonMark HeadingPermalinkExtension in Factories/CustomMarkdownFactory.php
                    uniqueRefs('mdnote-');
                    initGototopHandler();
                }
                if (OPTS.td_h_ctrl ?? false) { // cell height control
                    initTdHCtrl();
                    checkAndTriggerCheckboxes(mutation.target);
                }
            }
        });
    });


    observer.observe(document.body, {
        childList: true,
        subtree: true,
    });
}

const initMd = (options) => {
    OPTS = (typeof options == 'object' && options !== null ? Object.assign(getOptions(), options) : getOptions());
    let initExtObs = 0;
    if (OPTS.ext_fn ?? false) {
        // footnotes - prefixes declared for CommonMark FootnoteExtension in Factories/CustomMarkdownFactory.php
        uniqueRefs('fn_');
        uniqueRefs('fnref_');
        initExtObs++;
    }
    if (OPTS.ext_toc ?? false) {
        // table of contents / heading permalink - prefix declared for CommonMark HeadingPermalinkExtension in Factories/CustomMarkdownFactory.php
        uniqueRefs('mdnote-');
        initGototopHandler();
        initExtObs++;
    }
    if (OPTS.td_h_ctrl ?? false) { // cell height control
        initTdHCtrl();
        initTdHObserver();
        initExtObs++;
    }
    if (initExtObs > 0) { // lazy loading content - post-processing for extensions and cell height control
        initChildListObserver();
    }
}

export { initMd };