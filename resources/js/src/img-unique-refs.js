export const uniqueRefs = (
    root,
    idPrefix,
    sectionSelector
) => {
    // notes can be linked multiple on record pages - especially on the INDI page
    // so IDs aren't unique anymore and need to be corrected in order to make links work again
    const nodes =
        Array.from(
            root.querySelectorAll(`[id^="${idPrefix}"]`)
        );

    if (nodes.length === 0) return;

    const usedIds =
        new Set(nodes.map(el => el.id));

    const groups = new Map();

    nodes.forEach(el => {

        const base = el.id.replace(/_\d+$/, '');

        if (!groups.has(base)) {
            groups.set(base, []);
        }

        groups.get(base).push(el);
    });

    for (const [baseId, elements] of groups.entries()) {

        if (elements.length <= 1) continue;

        let index = 1;

        for (let i = 1; i < elements.length; i++) {

            let newId;

            do {

                newId = `${baseId}_${index++}`;

            } while (usedIds.has(newId));

            usedIds.add(newId);

            const el = elements[i];

            const container =
                el.closest(sectionSelector);

            if (!container) continue;

            el.id = newId;

            container
                .querySelectorAll(`a[href="#${baseId}"]`)
                .forEach(a =>
                    a.setAttribute('href', `#${newId}`)
                );
        }
    }
};