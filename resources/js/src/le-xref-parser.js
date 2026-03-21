export const getLErecTypes = (asString = false) => {

    const rectypes = {
        i: 'individual',
        f: 'family',
        s: 'source',
        r: 'repository',
        n: 'note',
        l: 'sharedPlace'
    };

    if (asString) {

        return Object.entries(rectypes)
            .map(([k, v]) => `${k}=${v}`)
            .join(', ');
    }

    return rectypes;
};



export const parseCrossReferenceLink = (href, rectypes) => {

    const match =
        href.match(
            new RegExp(
                "^([" +
                Object.keys(rectypes).join('') +
                "])?@([^@]+)@(.*)",
                'i'
            )
        );

    if (!match) {
        console.warn('LE-Mod xrefs: wt cross-reference - syntax error in', href)
        return null;
    }

    let [, type, xref, param] = match;

    const dia = (/ dia/i.test(param));

    param = param.replace(/ dia/i, '');

    return {
        type: type ? type.toLowerCase() : '',
        xref: xref.toUpperCase(),
        newtree: param,
        dia
    };
};