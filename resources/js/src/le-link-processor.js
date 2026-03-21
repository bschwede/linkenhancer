import { LINK_SELECTOR } from './le-config.js';
import { getLErecTypes, parseCrossReferenceLink } from './le-xref-parser.js';
import { changeTagName } from './le-utils.js';
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


    const processLink = startlink => {

        const href = startlink.getAttribute("href");

        if (!href) return;

        const [base, hash] = href.split("#@");

        if (!hash) return;

        const params = new URLSearchParams(hash);

        const matchingKeys =
            Object.keys(LEtargets) // important to match against key order of target config, where wt-key is the first; only works, if there are no nnumeric keys!
                .filter(k => params.has(k));

        const unknownKeys = 
            [...new Set(
                [...params.keys()]
                    .filter(k => !matchingKeys.includes(k))
            )].sort(); // list unknown parameter keys once in lexicographical order

        let lastLink = null;
        const diatitle = $("a.dropdown-item.menu-chart-tree[role=menuitem]").text().trim() || LEoptions.I18N['Interactive tree'];

        const fragment =
            document.createDocumentFragment()

        let wtcnt = 0

        matchingKeys.forEach(key => {

            const options = params.getAll(key); // support multiple entries per key

            options.forEach(option => {
                if (!option) return;

                if (key === 'wt') {
                    wtcnt++;

                    const parsed =
                        parseCrossReferenceLink(option, rectypes);

                    if (!parsed) return;

                    let { type, xref, newtree, dia } = parsed;
                    newtree = (newtree === LEoptions.tree ? null : newtree);

                    if ((!type && type !== '') || !xref) {
                        lastLink = setLink(
                            document,
                            startlink,
                            lastLink, 
                            '',
                            LEcfg[key].name + " - " + LEoptions.I18N['syntax error'] + "!", 
                            LEcfg[key].cname,
                            LEoptions,
                            fragment
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
                        startlink = changeTagName(document, startlink, 'mark');
                        thisXrefShown = true;
                    }

                    const urlxref =
                        url +
                        separator[LEoptions.urlmode].path +
                        (type !== '' ? rectypes[type] : 'goto-xref') +
                        separator[LEoptions.urlmode].path +
                        xref;

                    lastLink =
                        setLink(
                            document,
                            startlink,
                            lastLink,
                            urlxref,
                            `${LEtargets[key].name} - ${xref}${xrefsuffix}`,
                            LEtargets[key].cname,
                            LEoptions,
                            fragment
                        );
                    if (wtcnt > 1) {
                        lastLink.innerHTML = '<i class="fa fa-link" aria-hidden="true"></i>';
                    } else {
                        lastLink.classList.add('icon-wt-xref');
                    }

                    if (type == 'i' && dia && !thisXrefShown) { // diagram link for individuals record
                        let diaurl = url.replace(
                                "/tree/".replaceAll('/', separator[LEoptions.urlmode].path),
                                "/module/tree/Chart/".replaceAll('/', separator[LEoptions.urlmode].path)
                            ) + separator[LEoptions.urlmode].option + "xref=" + xref;
                        lastLink = setLink(
                            document,
                            startlink,
                            lastLink,
                            diaurl,
                            `${diatitle} - ${xref}`,
                            'icon-wt-dia',
                            LEoptions,
                            fragment
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

                    title = title.replace(/\$ID/ig, decodeURIComponent(option));

                    lastLink =
                        setLink(
                            document,
                            startlink,
                            lastLink,
                            url,
                            title,
                            LEtargets[key].cname,
                            LEoptions,
                            fragment
                        );
                }
            });
        });


        if (unknownKeys.length) {

            setLink(
                document,
                startlink,
                lastLink,
                '',
                LEoptions.I18N["param error"] + ": " + unknownKeys.join(', '),
                '',
                LEoptions,
                fragment
            );
        }

        if (fragment.childNodes.length) {
            startlink.after(fragment)
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