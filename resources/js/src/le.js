import { getLETargetCfg, getLEOptions, LINK_SELECTOR } from './le-config.js';
import { processLinks } from './le-link-processor.js';
import { getLErecTypes } from './le-xref-parser.js';
import { createDomObserver } from './dom-observer-factory.js';


export let LEtargets;
let LEoptions;


export const initLEext = (
    document,
    I18N,
    targets,
    options
) => {

    LEoptions =
        typeof options === 'object'
            ? Object.assign(getLEOptions(), options)
            : getLEOptions();
    
    LEoptions.I18N = I18N; // temporary - should be set in php header routine

    LEtargets =
        typeof targets === 'object'
            ? Object.assign(getLETargetCfg(LEoptions.I18N, getLErecTypes), targets)
        : getLETargetCfg(LEoptions.I18N, getLErecTypes);


    LEoptions.thisXref =
        String(LEoptions.thisXref).toUpperCase();


    createDomObserver({

        root: document.body,

        match: node =>
            node.tagName === "A",

        collect: node =>
            node.querySelectorAll?.(LINK_SELECTOR),

        process: link =>
            processLinks(
                document,
                LEtargets,
                LEoptions,
                link
            ),

        initialScan: true
    })
};



export const getLEhelpInfoExt = (LEtargets) => {

    const lis = [];

    Object.keys(LEtargets).forEach((key) => {
        let cfg = LEtargets[key];
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
};