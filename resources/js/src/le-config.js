import { i18nMixin } from './i18n-mixin.js';

export const LINK_SELECTOR = "a[href*='#@']";

export const getLEOptions = () => ({
    ...i18nMixin,
    thisXref: '',
    openInNewTab: true,
    tree: '',
    baseurl: '',
    urlmode: 'standard',
});


export const getLETargetCfg = (options, getLErecTypes) => {
    let cfg = //+++ code-snippet begin next line - used for display in admin settings page
{   // link targets
    // - key = query parameter key;
    // - name = label, placeholder $ID for inserting given id
    // - url = service url- id will be appended to the end, can also be a function(id, title)
    // - cname = css class name(s) whitespace separated
    // - help = [{n:'', e:''},..] - optional parameter examples (in e) with explanation (in n)
    "wt": { // placeholder - is always the first link
        name: 'webtrees ' + options.i18n('cross reference'),
        help: [
            { n: options.i18n('wt-help1').replace(/%s/, getLErecTypes(1)), e: 'n@XREF@' },
            { n: options.i18n('wt-help2') + ' ' + options.i18n('Interactive tree'), e: 'i@XREF@othertree+dia' },
            { n: options.i18n('wt-help3'), e: '@XREF@' }
        ]
    },
    "wp": {
        name: 'Wikipedia',
        url: (id, title) => {
            let parts = id.split('/');
            if (parts.length < 2) {
                title = title + " - " + options.i18n('syntax error') + "!";
                return { url: '', title };
            }
            let subdomain = parts[0];
            let article = parts.slice(1).join('/');
            title = `${title} - ` + decodeURIComponent(article) + ` (${subdomain})`;
            return { url: `https://${subdomain}.wikipedia.org/wiki/${article}`, title };
        },
        cname: 'icon-wp',
        help: [
            { n: options.i18n('wp-help1'), e: 'de/Webtrees' },
            { n: options.i18n('wp-help2'), e: 'en/Webtrees' },
            { n: options.i18n('wp-help3'), e: 'other-subdomain/Webtrees' },
        ]
    },
    "www": {
        name: options.i18n('www'),
        url: 'https://www.wer-wir-waren.at/daten?guid=',
        cname: 'icon-www'
    },
    "ofb": {
        name: options.i18n('oofb'),
        url: (id, title) => {
            let parts = id.split('/');
            if (parts.length != 2) {
                title = title + ' - ' + options.i18n('syntax error') + "!";
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
            { n: options.i18n('ofb-help1'), e: 'dornbirn/BDDBBFB7531C4A2EB0F56852BDD7720F8697A5' },
        ]
    },
    "gw": {
        name: 'GenWiki - $ID',
        url: "https://wiki.genealogy.net/",
        cname: 'icon-compgen'
    },
    "gov": {
        name: options.i18n('gov') + ' - $ID',
        url: "https://gov.genealogy.net/item/show/",
        cname: 'icon-compgen'
    },
    "gedbas": {
        name: options.i18n('gedbas'),
        url: (id, title) => {
            let idtype = !id.match(/^[\da-f\-\/]+$/i) ? 'error' : id.split('/').length;
            idtype = idtype === 1 && !id.match(/^\d+$/) ? 'uid' : idtype;
            switch (idtype) {
                case 1: // dataset number
                    return { url: `https://gedbas.genealogy.net/person/show/${id}`, title };
                case 2: // UID
                    return { url: `https://gedbas.genealogy.net/person/uid/${id}`, title };
                case 'uid': // UID without database number
                    return { url: `https://gedbas.genealogy.net/uid/${id}`, title };
                default:
                    title = title + ' - ' + options.i18n('syntax error') + "!";
                    return { url: '', title };
            }
        },
        cname: 'icon-compgen',
        help: [
            { n: options.i18n('gedbas-help1'), e: '1051362866' },
            { n: options.i18n('gedbas-help2'), e: '56789/136049f257c96e34430aec053fa0fbce865c' },
            { n: options.i18n('gedbas-help3'), e: 'b43cd5ad-695c-4e1f-9c9d-25918d292256' },
        ]
    },
    "ewp": {
        name: options.i18n('ewp') + ' (westpreussen.de)',
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
                title = title + ' - ' + options.i18n('syntax error') + "!";
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
            return { url: `https://www.openstreetmap.org/${urlsearch}#map=${map}`, title };
        },
        cname: 'icon-osm',
        help: [
            { n: options.i18n('osm-help1'), e: '17/53.619095/10.037395' },
            { n: options.i18n('osm-help2'), e: '17/53.619095/10.037395/!' },
            { n: options.i18n('osm-help3') + ' <a href="https://wiki.openstreetmap.org/wiki/DE:Browsing">OSM-Wiki</a>', e: '16/50.11185/8.09636/way/60367151' },
            { n: options.i18n('osm-help4'), e: '13/50.09906/8.04660/?relation=403139' },
        ]
    },
    "wit": {
        name: 'WikiTree - $ID',
        url: 'https://www.wikitree.com/wiki/',
        cname: 'icon-wit'
    },
    "vl": {
        name: options.i18n('des-vl'),
        url: 'https://des.genealogy.net/search/uuid/',
        cname: 'icon-compgen'
    },    
}
//--- code-snippet end
    return cfg
}