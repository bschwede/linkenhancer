import { getLETargetCfg, getLEOptions } from './le-config.js';
import { observeDomLinks } from './le-observer.js';
import { processLinks } from './le-link-processor.js';
import { getLErecTypes } from './le-xref-parser.js';


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


    const processNode =
        node => processLinks(
            document,
            LEtargets,
            LEoptions,
            node
        );


    processNode(null); // process all available links

    observeDomLinks(
        document,
        processNode
    );
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