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


        matchingKeys.forEach(key => {

            const option = params.get(key);

            if (!option) return;

            if (key === 'wt') {

                const parsed =
                    parseCrossReferenceLink(option, rectypes);

                if (!parsed) return;

                let { type, xref, newtree, dia } = parsed;

                let url = LEoptions.baseurl;

                if (newtree) {
                    url =
                        url.replace(
                            `/tree/${LEoptions.tree}`,
                            `/tree/${newtree}`
                        );
                }

                const urlxref =
                    url +
                    separator[LEoptions.urlmode].path +
                    (type || 'goto-xref') +
                    separator[LEoptions.urlmode].path +
                    xref;

                const nextLink =
                    lastLink ? createLink(document) : link;

                lastLink =
                    setLink(
                        document,
                        nextLink,
                        lastLink,
                        urlxref,
                        `${LEtargets[key].name} - ${xref}`,
                        LEtargets[key].cname,
                        LEoptions.openInNewTab
                    );

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

                title =
                    title.replace(/\$ID/ig,
                        decodeURIComponent(option));

                lastLink =
                    setLink(
                        document,
                        nextLink,
                        lastLink,
                        url,
                        title,
                        LEtargets[key].cname,
                        LEoptions.openInNewTab
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
                "param error: " + unknownKeys.join(', ')
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