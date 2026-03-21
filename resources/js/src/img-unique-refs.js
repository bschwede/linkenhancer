export const uniqueRefs = (
    root,
    idPrefix,
    sectionSelector
) => {
    // notes can be linked multiple on record pages - especially on the INDI page
    // so IDs aren't unique anymore and need to be corrected in order to make links work again
    const elems = root.querySelectorAll(`[id^="${idPrefix}"]`);
    const seen = {};

    elems.forEach(el => seen[el.id] = (seen[el.id] || 0) + 1);
    //console.log('All IDs: ', seen);
    for (const [baseId, idCount] of Object.entries(seen)) {
        if (idCount > 1) {
            let elems = root.querySelectorAll(`[id="${baseId}"]`);
            for (let nthElem = 1; nthElem < idCount; nthElem++) {
                //it's easier now to detect md rendered content - before: let noteElem = elems[nthElem].closest('div.wt-fact-notes, td > div:not(.footnotes), td'); // div.wt-fact-notes - notes for names on INDI page
                let noteElem = elems[nthElem].closest(sectionSelector); // see LinkEnhancerModule::STDCLASS_MD_CONTENT
                if (noteElem) {
                    let lastIdx = getIdMaxIndexSuffix(root, baseId); // if called by observer, there are already adjusted IDs
                    let newId = `${baseId}_${nthElem + lastIdx}`;
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

};

export const getIdMaxIndexSuffix = (root, baseId) => { // helper for uniqueRefs - important for lazy loading content
    const elements = document.querySelectorAll(`[id^="${baseId}_"]`);

    const indices = Array.from(elements).map(el => {
        const id = el.id;
        const lastUnderscore = id.lastIndexOf('_');
        const suffix = id.substring(lastUnderscore + 1);
        return parseInt(suffix, 10);
    }).filter(n => !isNaN(n));

    return (indices.length > 0 ? Math.max(...indices) : 0);
}
