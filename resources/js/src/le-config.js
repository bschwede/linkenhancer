export const LINK_SELECTOR = "a[href*='#@']";

export const getLEOptions = () => ({
    I18N: {},
    thisXref: '',
    openInNewTab: true,
    tree: '',
    baseurl: '',
    urlmode: 'standard',
});


export const getLETargetCfg = (I18N, getLErecTypes) => {
    let cfg = //+++ code-snippet begin next line - used for display in admin settings page
{   // link targets
    // - key = query parameter key;
    // - name = label, placeholder $ID for inserting given id
    // - url = service url- id will be appended to the end, can also be a function(id, title)
    // - cname = css class name(s) whitespace separated
    // - help = [{n:'', e:''},..] - optional parameter examples (in e) with explanation (in n)
    "wt": { // placeholder - is always the first link
        name: 'webtrees ' + I18N['cross reference'],
        help: [
            { n: I18N['wt-help1'].replace(/%s/, getLErecTypes(1)), e: 'n@XREF@' },
            { n: I18N['wt-help2'] + ' ' + I18N['Interactive tree'], e: 'i@XREF@othertree+dia' },
            { n: I18N['wt-help3'], e: '@XREF@' }
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
            return { url: `https://www.openstreetmap.org/${urlsearch}#map=${map}`, title };
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
    return cfg
}