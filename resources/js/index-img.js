// Markdown Enhancements
const getOptions = () => {
    return {
        I18N: {
            limitheight: 'Limit cell height',
        },
        ext_fn: true,
        ext_toc: true,
        td_h_ctrl: 1,
    }
}
let OPTS = getOptions();

const mdsection_selector = 'section.md-content';

const td_h_selector = 'input.td-h-checker[type="checkbox"]';
const td_h_class = 'md-td-heightctrl';

const escapeHtmlAttribute = (str) => {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

const findCssRule = (selector, property) => {
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


const parseCssValue = (cssValue, relativeToVh = window.innerHeight) => {
    if (cssValue.includes('vh')) {
        return parseFloat(cssValue) / 100 * relativeToVh;
    }
    if (cssValue.includes('px')) {
        return parseFloat(cssValue);
    }
    return Infinity; // none, auto, etc.
}

const gotoTop = (refElement = null) => {
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
                    let newId = `${baseId}_${nthElem}`;
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

const initTdHCtrl = () => {
    const checked = (OPTS.td_h_ctrl === 2) ? ' checked' : '';
    const title = escapeHtmlAttribute(OPTS.I18N.limitheight ?? '');
    const html = `<div class="md-sticky-wrapper me-0 float-end"><input class="td-h-checker" type="checkbox" title="${title}"${checked}></div>`;

    [...new Set(
        Array.from(document.querySelectorAll(mdsection_selector))
            .map(el => el.closest('td'))
            .filter(Boolean) // remove null and undefined values from an array - https://mikebifulco.com/posts/javascript-filter-boolean
    )].forEach(targetTd => {
        // 2-column tables: first td contains cehckbox, second td is controlled
        const firstTd = targetTd.parentElement.children[0];

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

const initTdHObserver = () => {
    // global observer for visibility changes 
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

    
    function checkAndTriggerCheckboxes(node) { // trigger all checkboxes in a node when visible
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

    visibilityObserver.observe(document.body, {
        attributes: true,
        subtree: true,
        attributeFilter: ['style', 'class'],
        attributeOldValue: false,
    });    
}

const initMd = (options) => {
    OPTS = (typeof options == 'object' && options !== null ? Object.assign(getOptions(), options) : getOptions());

    if (OPTS.ext_fn ?? false) {
        // footnotes - prefixes declared for CommonMark FootnoteExtension in Factories/CustomMarkdownFactory.php
        uniqueRefs('fn_');
        uniqueRefs('fnref_');
    }
    if (OPTS.ext_toc ?? false) {
        // table of contents / heading permalink - prefix declared for CommonMark HeadingPermalinkExtension in Factories/CustomMarkdownFactory.php
        uniqueRefs('mdnote-');

        // goto top of cell (with toc in dropdown)
        $("button.gototop").on("click", (e) => {
            gotoTop(e.target.closest("td"));
        });

    }

    if (OPTS.td_h_ctrl ?? false) { // cell height control
        initTdHCtrl();
        initTdHObserver();
    }
}

export { initMd };