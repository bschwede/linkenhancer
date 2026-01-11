// Enhanced links (XREF and external targets) in notes
function getLECfg() {
    let cfg = //+++ code-snippet begin next line - used for display in admin settings page
{ // link targets
  // - key = query parameter key;
  // - name = label, placeholder $ID for inserting given id
  // - url = service url- id will be appended to the end, can also be a function(id, title)
  // - cname = css class name(s) whitespace separated
  // - help = [{n:'', e:''},..] - optional parameter examples (in e) with explanation (in n)
    "wt": { // placeholder - is always the first link
        name: 'webtrees ' + I18N['cross reference'],
        help: [ 
            {n: I18N['wt-help1'].replace(/%s/, getLErecTypes(1)), e:'n@XREF@'}, 
            {n: I18N['wt-help2'] + ' ' + I18N['Interactive tree'], e: 'i@XREF@othertree+dia' },
            {n: I18N['wt-help3'], e: '@XREF@' }
        ]
    },
    "wp": {
        name: 'Wikipedia',
        url: (id, title) => {
            let parts = id.split('/');
            if (parts.length < 2) {
                title = title + " - " + I18N['syntax error'] + "!";
                return { url: '', title };
            }
            let subdomain = parts[0];
            let article = parts.slice(1).join('/');
            title = `${title} - ` + decodeURIComponent(article);
            return { url: `https://${subdomain}.wikipedia.org/wiki/${article}`, title };
        },
        cname: 'icon-wp',
        help: [
            { n: I18N['wp-help1'], e: 'de/Webtrees' },
            { n: I18N['wp-help2'], e: 'en/Webtrees' },
            { n: I18N['wp-help3'], e: 'other-subdomain/Webtrees' },
        ]
    },
    "www": {
        name: I18N['www'],
        url: 'https://www.wer-wir-waren.at/daten?guid=',
        cname: 'icon-www'
    },
    "ofb": {
        name: I18N['oofb'],
        url: (id, title) => {
            let parts = id.split('/', 2);
            if (parts.length != 2) {
                title = title + ' - ' + I18N['syntax error'] + "!";
                return { url: '', title };
            }
            let ofb = parts[0];
            let uid = parts[1];
            const capitalize = s => (s && String(s[0]).toUpperCase() + String(s).slice(1)) || "";
            title = title.replace(/%s/, capitalize(decodeURIComponent(ofb)));
            return { url: `https://ofb.genealogy.net/famreport.php?ofb=${ofb}&UID=${uid}`, title };
        },
        cname: 'icon-compgen',
        help: [
            { n: I18N['ofb-help1'], e: 'dornbirn/BDDBBFB7531C4A2EB0F56852BDD7720F8697A5' },
        ]        
    },
    "gw": {
        name: 'GenWiki - $ID',
        url: "https://wiki.genealogy.net/",
        cname: 'icon-compgen'
    },
    "gov": {
        name: I18N['gov'] + ' - $ID',
        url: "https://gov.genealogy.net/item/show/",
        cname: 'icon-compgen'
    },
    "gedbas": {
        name: I18N['gedbas'],
        url: (id, title) => {
            let parts = id.split('/');
            switch (parts.length) {
                case 1: // dataset number
                    return { url: `https://gedbas.genealogy.net/person/show/${id}`, title };
                case 2: // UID
                    return { url: `https://gedbas.genealogy.net/person/uid/${id}`, title };
                default:
                    title = title + ' - ' + I18N['syntax error'] + "!";
                    return { url: '', title };
            }
        },
        cname: 'icon-compgen',
        help: [
            { n: I18N['gedbas-help1'], e: '1234567' },
            { n: I18N['gedbas-help2'], e: '56789/136049f257c96e34430aec053fa0fbce865c' },
        ]
    },    
    "ewp": {
        name: I18N['ewp'] + ' (westpreussen.de)',
        url: 'https://westpreussen.de/tngeinwohner/getperson.php?tree=DB1&personID=',
        cname: 'icon-ewp'
    },
    "fsft": {
        name: 'FamilySearch Family Tree - $ID',
        url: 'https://www.familysearch.org/tree/person/details/',
        cname: 'icon-fsft'
    },
    "osm": {
        name: 'OpenStreetMap',
        url: (id, title) => { 
            let parts = id.split('/');
            if (parts.length < 3) {
                title = title + ' - ' + I18N['syntax error'] + "!";
                return { url: '', title };
            }
            let map = parts.slice(0, 3).join('/');
            let urlsearch = '';
            if (parts.length > 3 && parts[3].trim()) {
                if (parts[3].trim() === '!') {
                    urlsearch = `?mlat=${parts[1]}&mlon=${parts[2]}`;
                } else {
                    urlsearch = parts.slice(3).join('/');
                }
            }
            return {url:`https://www.openstreetmap.org/${urlsearch}#map=${map}`, title};
        },
        cname: 'icon-osm',
        help: [
            { n: I18N['osm-help1'], e: '17/53.619095/10.037395' },
            { n: I18N['osm-help2'], e: '17/53.619095/10.037395/!' },
            { n: I18N['osm-help3'] + ' <a href="https://wiki.openstreetmap.org/wiki/DE:Browsing">OSM-Wiki</a>', e: '16/50.11185/8.09636/way/60367151' },
            { n: I18N['osm-help4'], e: '13/50.09906/8.04660/?relation=403139' },
        ]
    },
    "wit": {
        name: 'WikiTree - $ID',
        url: 'https://www.wikitree.com/wiki/',
        cname: 'icon-wit'
    },    
}
//--- code-snippet end
    return cfg;
}

const getLEOptions = () => {
    return {
        thisXref: '',
        openInNewTab: true,
    }
}
let LEcfg = getLECfg();
let LEoptions = getLEOptions();


function initLE(cfg, options) {
    LEcfg = (typeof cfg == 'object' && cfg !== null ? Object.assign(getLECfg(), cfg) : getLECfg());
    LEoptions = (typeof options == 'object' && options !== null ? Object.assign(getLEOptions(), options) : getLEOptions());
    LEoptions.thisXref = String(LEoptions.thisXref).toUpperCase();
    observeDomLinks();
}

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

function getLEhelpInfo() {
    let lis = [];
    Object.keys(LEcfg).forEach((key) => {
        let cfg = LEcfg[key];
        let html = '<u>' + cfg.name.replace(/ - \$ID/, '').replace(/%s/, '').replace(/ {2,}/, ' ') + ':</u>';
        if (!cfg['help']) {
            html += ` <code>${key}=ID</code>`;
        } else {
            let opthtml = [];
            cfg['help'].forEach(opt => {
                opthtml.push(`<code>${key}=${opt['e']}</code> - <em>${opt['n']}</em>`);
            });
            html += '<br>' + opthtml.join('<br>');
        }
        lis.push(html);
    });
    return '<ul><li>' + lis.join('</li><li>') + '</li></ul>';
}

function getLErecTypes(asString) {
    const rectypes = { 'i': 'individual', 'f': 'family', 's': 'source', 'r': 'repository', 'n': 'note', 'l': 'sharedPlace' };
    if (asString) {
        let arr = [];
        Object.keys(rectypes).forEach(key => arr.push(`${key}=${rectypes[key]}`))
        return arr.join(', ');
    }
    return rectypes;
}

function changeTagName(element, newTag) {
    const replacement = document.createElement(newTag);

    // move child nodes
    while (element.firstChild) {
        replacement.appendChild(element.firstChild);
    }

    // clone attributes
    for (let i = 0; i < element.attributes.length; i++) {
        const attr = element.attributes[i].cloneNode();
        replacement.attributes.setNamedItem(attr);
    }

    // replace old element in DOM
    element.parentNode.replaceChild(replacement, element);

    return replacement;
}

function processLinks(linkElement) {
    const rectypes = getLErecTypes();
    const separator = { 'default': { 'path': '%2F', 'option': '&' }, 'pretty': { 'path': '/', 'option': '?' } };

    function getNextLink(link, lastlink) {
        if (!lastlink) {
            return link;
        } else {
            return (document.createElement("a"));
        }
    }
    function setLink(link, lastlink, href, title, classname) {
        if (href) {
            link.setAttribute("href", href);
            if (LEoptions.openInNewTab) {
                link.setAttribute("target", '_blank');
            }
        } else { // assume syntax error
            link.onclick = (e) => { alert(link.title ?? I18N['syntax error'] + "!"); e.preventDefault(); }
            link.classList.add('icon-error');
        }
        if (title) {
            link.setAttribute("title", title);
            link.setAttribute("aria-label", title);
        }
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
        const match = href.match(new RegExp("^([" + Object.keys(rectypes).join('') + "])?@([^@]+)@(.*)", 'i'));
        if (!match) {
            console.warn('LE-Mod xrefs: wt cross-reference - syntax error in' ,href);
            return {};
        }
        let [, type, xref, param] = match;
        let dia = (/ dia/i.test(param));
        param = param.replace(/ dia/i, '');

        return { type: (type ? type.toLowerCase() : ''), xref: xref.toUpperCase(), newtree: param, dia };
    }

    function processLink(link) {
        const href = link.getAttribute("href");
        if (!(typeof href === 'string' || href instanceof String)) return;
        const [base, hash] = href.split("#@");

        if (!hash) return;

        const params = new URLSearchParams(hash);
        const matchingKeys = Object.keys(LEcfg).filter(key => params.has(key));
        const unknownKeys = Array.from(params.keys()).filter(key => !matchingKeys.includes(key));

        let lastLink = null;
        matchingKeys.forEach(key => {
            const option = params.get(key);
            if (!option) return;

            if (key == 'wt') {
                const { type, xref, newtree, dia } = parseCrossReferenceLink(option);
                if ((!type && type !== '') || !xref) {
                    let nextLink = getNextLink(link, lastLink);
                    lastLink = setLink(nextLink, lastLink, '', LEcfg[key].name + " - " + I18N['syntax error'] + "!", LEcfg[key].cname);
                    lastLink.classList.add('icon-wt-xref');
                    return;
                }
                let url = baseurl;
                let thisXrefShown = false;
                let xrefsuffix = '';
                if (newtree) {
                    url = url.replace(`/tree/${tree}`.replaceAll('/', separator[urlmode].path),
                        `/tree/${newtree}`.replaceAll('/', separator[urlmode].path));
                    xrefsuffix = ` @ ${newtree}`;
                } else if (LEoptions.thisXref === xref) {
                    link = changeTagName(link, 'strong');
                    thisXrefShown = true;
                }
                let urlxref = url + separator[urlmode].path + (type !== '' ? rectypes[type] : 'goto-xref') + separator[urlmode].path + xref;
                let nextLink = getNextLink(link, lastLink);
                lastLink = setLink(nextLink, lastLink, urlxref, LEcfg[key].name + ` - ${xref}${xrefsuffix}`, LEcfg[key].cname);
                lastLink.classList.add('icon-wt-xref');

                if (type == 'i' && dia && !thisXrefShown) {
                    let diaurl = url.replace("/tree/".replaceAll('/', separator[urlmode].path), "/module/tree/Chart/".replaceAll('/', separator[urlmode].path)) + separator[urlmode].option + "xref=" + xref;
                    nextLink = getNextLink(link, lastLink);
                    lastLink = setLink(nextLink, lastLink, diaurl, `${diatitle} - ${xref}`, 'icon-wt-dia');//'menu-chart-tree');
                }
            } else {
                let url = '';
                let title = LEcfg[key].name;
                if (typeof LEcfg[key].url == 'function') {
                    ({ url, title } = LEcfg[key].url(option, title));
                } else {
                    url =  LEcfg[key].url + encodeURIComponent(option);
                }
                let nextLink = getNextLink(link, lastLink);
                title = title.replace(/\$ID/ig, decodeURIComponent(option));
                lastLink = setLink(nextLink, lastLink, url, title, LEcfg[key].cname);
            }
        });
        if (unknownKeys.length > 0) {
            let nextLink = getNextLink(link, lastLink);
            lastLink = setLink(nextLink, lastLink, '', I18N['param error'] + ": " + unknownKeys.sort().join(', '));
        }
    }

    // Alle a-Tags durchlaufen
    const { tree, baseurl, urlmode } = getTreeInfoFromURL();
    const diatitle = $("a.dropdown-item.menu-chart-tree[role=menuitem]").text().trim() || I18N['Interactive tree'];

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
                            //console.log(node);
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

export { initLE, getLEhelpInfo };