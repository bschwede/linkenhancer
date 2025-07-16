const supportedKeys = { // as link targets: key = query parameter key; name = label; url = service url- id will be appended to the end; cname = css class name
    "wt": {
        name: 'webtrees Querverweis',
        cname: ''
    },
    "wpde": {
        name: 'Wikipedia DE - $ID',
        url: "https://de.wikipedia.org/wiki/",
        cname: 'icon-wp'
    },
    "dbfam": {
        name: 'Dornbirn Familienbuch',
        url: 'https://www.wer-wir-waren.at/daten?guid=',
        cname: 'icon-dbfam'
    },
    "ofbdb": {
        name: 'Online-Ortsfamilienbuch Dornbirn bei CompGen',
        url: "https://ofb.genealogy.net/famreport.php?ofb=dornbirn&UID=",
        cname: 'icon-compgen'
    },
    "ofbs": {
        name: 'Online-Ortsfamilienbuch Saatzig bei CompGen',
        url: "https://ofb.genealogy.net/famreport.php?ofb=saatzig&UID=",
        cname: 'icon-compgen'
    },
    "gw": {
        name: 'GenWiki - $ID',
        url: "https://wiki.genealogy.net/",
        cname: 'icon-compgen'
    },
    "gov": {
        name: 'Das Geschichtliche Orts-Verzeichnis - $ID',
        url: "https://gov.genealogy.net/item/show/",
        cname: 'icon-compgen'
    },
    "ewp": {
        name: 'Einwohnerdatenbank - Familienforschung in Westpreussen (westpreussen.de)',
        url: 'https://westpreussen.de/tngeinwohner/getperson.php?personID=',
        cname: 'icon-ewp'
    },
    "fsft": {
        name: 'Family Search Tree - $ID',
        url: 'https://www.familysearch.org/tree/person/details/',
        cname: 'icon-fsft'
    }
    ,
    "osm": {
        name: 'OpenStreetMap',
        url: (id) => {
            let parts = id.split('/');
            let map = parts.slice(0, 3).join('/');
            let urlsearch = '';
            if (parts.length > 3 && parts[3].trim()) {
                if (parts[3].trim() === '!') {
                    urlsearch = `?mlat=${parts[1]}&mlon=${parts[2]}`;
                } else {
                    urlsearch = "?" + parts[3].trim();
                }
            }
            return `https://www.openstreetmap.org/${urlsearch}#map=${map}`;
        }, //16/47.38972/9.78414
        // Details siehe https://wiki.openstreetmap.org/wiki/DE:Browsing
        cname: 'icon-osm'
    }
};


function getTreeInfoFromURL() {
    const href = document.location.href;
    const match1 = href.match(/^.+?\/index\.php\?route=(?:%2F.+?)*%2Ftree%2F([^%\/#?]+)/i);
    const match2 = href.match(/^.+?\/tree\/([^\/#?]+)/i);
    const baseMatch = match1 || match2;
    if (!baseMatch) return {};

    const tree = baseMatch[1];
    const baseurl = baseMatch[0];
    const urlmode = baseurl.includes('index.php') ? 'default' : 'pretty';

    return { tree, baseurl, urlmode };
}

function processLinks(linkElement) {
    const rectypes = { 'i': 'individual', 'f': 'family', 's': 'source', 'r': 'repository', 'n': 'note', 'l': 'sharedPlace' };
    const separator = { 'default': { 'path': '%2F', 'option': '&' }, 'pretty': { 'path': '/', 'option': '?' } };

    function getNextLink(link, lastlink) {
        if (!lastlink) {
            return link;
        } else {
            return (document.createElement("a"));
        }
    }
    function setLink(link, lastlink, href, title, classname) {
        if (href) link.setAttribute("href", href);
        link.setAttribute("target", '_blank');
        if (title) link.setAttribute("title", title);
        if (classname) {
            const iconSpan = document.createElement("span");
            iconSpan.classList.add('linkicon');
            iconSpan.classList.add(classname);
            link.appendChild(iconSpan);
        }
        if (lastlink) lastlink.after(link);
        return link;
    }

    function parseCrossReferenceLink(href) {
        const match = href.match(new RegExp("^([" + Object.keys(rectypes).join('') + "])@([^@]+)@(.*)", 'i'));
        if (!match) {
            console.warn(`wt-Querverweis - Syntaxfehler in ${href}`);
            return {};
        }
        let [, type, xref, param] = match;
        let dia = (/ dia/i.test(param));
        param = param.replace(/ dia/i, '');

        return { type: type.toLowerCase(), xref, newtree: param, dia };
    }

    function processLink(link) {
        const href = link.getAttribute("href");
        const [base, hash] = href.split("#@");

        if (!hash) return;

        const params = new URLSearchParams(hash);
        const matchingKeys = Object.keys(supportedKeys).filter(key => params.has(key));

        let lastLink = null;
        matchingKeys.forEach(key => {
            const option = params.get(key);
            if (!option) return;

            if (key == 'wt') {
                const { type, xref, newtree, dia } = parseCrossReferenceLink(option);
                if (!type || !xref) {
                    let nextLink = getNextLink(link, lastLink);
                    lastLink = setLink(nextLink, lastLink, '', supportedKeys[key].name + " - Syntaxfehler!", supportedKeys[key].cname);
                    lastLink.classList.add('icon-wt-xref-error');
                    return;
                }
                let url = baseurl;
                if (newtree) {
                    url = url.replace(`/tree/${tree}`.replaceAll('/', separator[urlmode].path),
                        `/tree/${newtree}`.replaceAll('/', separator[urlmode].path));
                }
                let urlxref = url + separator[urlmode].path + rectypes[type] + separator[urlmode].path + xref;
                let nextLink = getNextLink(link, lastLink);
                lastLink = setLink(nextLink, lastLink, urlxref, supportedKeys[key].name + ` - ${xref}`, supportedKeys[key].cname);
                lastLink.classList.add('icon-wt-xref');

                if (type == 'i' && dia) {
                    let diaurl = url.replace("/tree/".replaceAll('/', separator[urlmode].path), "/module/tree/Chart/".replaceAll('/', separator[urlmode].path)) + separator[urlmode].option + "xref=" + xref;
                    nextLink = getNextLink(link, lastLink);
                    lastLink = setLink(nextLink, lastLink, diaurl, `${diatitle} - ${xref}`, 'icon-wt-dia');//'menu-chart-tree');
                }
            } else {
                let url = (typeof supportedKeys[key].url == 'function' ?
                    supportedKeys[key].url(option) :
                    supportedKeys[key].url + encodeURIComponent(option)
                );
                let nextLink = getNextLink(link, lastLink);
                let title = supportedKeys[key].name.replace(/\$ID/ig, decodeURIComponent(option));
                lastLink = setLink(nextLink, lastLink, url, title, supportedKeys[key].cname);
            }
        });
    }

    // Alle a-Tags durchlaufen
    const { tree, baseurl, urlmode } = getTreeInfoFromURL();
    const diatitle = $("a.dropdown-item.menu-chart-tree[role=menuitem]").text().trim() || 'Interaktives Sanduhrdiagramm';

    if (linkElement) {
        processLink(linkElement);
    } else {
        document.querySelectorAll("a[href*='#@']")?.forEach(link => processLink(link) );
    }
}

function observeDomLinks() {
    const callback = function (mutationsList, observer) {
        for (const mutation of mutationsList) {
            if (mutation.type === 'childList') {
                mutation.addedNodes.forEach(node => {
                    if (node.nodeType === Node.ELEMENT_NODE) {
                        if (node.tagName === 'A') {
                            console.log(node);
                            processLinks(node);
                        }

                        const links = node.querySelectorAll?.('a');
                        links?.forEach(link => processLinks(link) );
                    }
                });
            }
        }
    };

    processLinks();
    const observer = new MutationObserver(callback);
    observer.observe(document.body, { childList: true, subtree: true });
}
