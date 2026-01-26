// Markdown Enhancements
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
                let noteElem = elems[nthElem].closest('div.wt-fact-notes, td > div:not(.footnotes), td'); // div.wt-fact-notes - notes for names on INDI page
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

const initMd = () => {
    // footnotes - prefixes declared for CommonMark FootnoteExtension in Factories/CustomMarkdownFactory.php
    uniqueRefs('fn_');
    uniqueRefs('fnref_');
    uniqueRefs('mdnote-');
}

export { initMd };