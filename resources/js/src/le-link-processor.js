import { LINK_SELECTOR } from './le-config.js';
import { getLErecTypes, parseCrossReferenceLink } from './le-xref-parser.js';
import { createLink, changeTagName } from './le-utils.js';
import { setLink } from './le-link-builder.js';


export const processLinks = (
    document,
    LEtargets,
    LEoptions,
    linkElement = null
) => {

    const rectypes = getLErecTypes();

    const separator = {
        default: { path: '%2F', option: '&' },
        pretty: { path: '/', option: '?' }
    };


    const processLink = link => {

        const href = link.getAttribute("href");

        if (!href) return;

        const [base, hash] = href.split("#@");

        if (!hash) return;

        const params = new URLSearchParams(hash);

        const matchingKeys =
            Object.keys(LEtargets)
                .filter(k => params.has(k));

        const unknownKeys =
            [...params.keys()]
                .filter(k => !matchingKeys.includes(k));

        let lastLink = null;
        const diatitle = $("a.dropdown-item.menu-chart-tree[role=menuitem]").text().trim() || LEoptions.I18N['Interactive tree'];

        matchingKeys.forEach(key => {

            const option = params.get(key);

            if (!option) return;

            if (key === 'wt') {

                const parsed =
                    parseCrossReferenceLink(option, rectypes);

                if (!parsed) return;

                let { type, xref, newtree, dia } = parsed;
                newtree = (newtree === LEoptions.tree ? null : newtree);

                if ((!type && type !== '') || !xref) {
                    let nextLink = getNextLink(link, lastLink);
                    lastLink = setLink(
                        document,
                        nextLink,
                        lastLink, 
                        '',
                        LEcfg[key].name + " - " + LEoptions.I18N['syntax error'] + "!", 
                        LEcfg[key].cname,
                        LEoptions
                    );
                    lastLink.classList.add('icon-wt-xref');
                    return;
                }

                let url = LEoptions.baseurl;
                let thisXrefShown = false;
                let xrefsuffix = '';                

                if (newtree) {
                    url = url.replace(`/tree/${LEoptions.tree}`.replaceAll('/', separator[LEoptions.urlmode].path),
                        `/tree/${newtree}`.replaceAll('/', separator[LEoptions.urlmode].path));
                    xrefsuffix = ` @ ${newtree}`;
                } else if (LEoptions.thisXref === xref) {
                    link = changeTagName(document, link, 'mark');
                    thisXrefShown = true;
                }

                const urlxref =
                    url +
                    separator[LEoptions.urlmode].path +
                    (type !== '' ? rectypes[type] : 'goto-xref') +
                    separator[LEoptions.urlmode].path +
                    xref;

                let nextLink =
                    lastLink ? createLink(document) : link;

                lastLink =
                    setLink(
                        document,
                        nextLink,
                        lastLink,
                        urlxref,
                        `${LEtargets[key].name} - ${xref}${xrefsuffix}`,
                        LEtargets[key].cname,
                        LEoptions
                    );
                lastLink.classList.add('icon-wt-xref');

                if (type == 'i' && dia && !thisXrefShown) {
                    let diaurl = url.replace(
                            "/tree/".replaceAll('/', separator[LEoptions.urlmode].path),
                            "/module/tree/Chart/".replaceAll('/', separator[LEoptions.urlmode].path)
                        ) + separator[LEoptions.urlmode].option + "xref=" + xref;
                    nextLink = lastLink ? createLink(document) : link;
                    lastLink = setLink(
                        document,
                        nextLink,
                        lastLink,
                        diaurl,
                        `${diatitle} - ${xref}`,
                        'icon-wt-dia',
                        LEoptions
                    );
                }
            } else {

                let url = '';
                let title = LEtargets[key].name;

                if (typeof LEtargets[key].url === 'function') {

                    ({ url, title } =
                        LEtargets[key].url(option, title));

                } else {

                    url =
                        LEtargets[key].url +
                        encodeURIComponent(option);
                }

                const nextLink =
                    lastLink ? createLink(document) : link;

                title = title.replace(/\$ID/ig, decodeURIComponent(option));

                lastLink =
                    setLink(
                        document,
                        nextLink,
                        lastLink,
                        url,
                        title,
                        LEtargets[key].cname,
                        LEoptions
                    );
            }

        });


        if (unknownKeys.length) {

            const nextLink =
                lastLink ? createLink(document) : link;

            setLink(
                document,
                nextLink,
                lastLink,
                '',
                LEoptions.I18N["param error"] + ": " + unknownKeys.join(', '),
                '',
                LEoptions
            );
        }

    };


    if (linkElement) {

        processLink(linkElement);

    } else {

        document
            .querySelectorAll(LINK_SELECTOR)
            .forEach(processLink);
    }
};