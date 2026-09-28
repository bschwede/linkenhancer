// Enhanced links (XREF and external targets) in notes
import { initLEext, getLEhelpInfoExt, LEtargets } from './src/le.js';
import { initXrefDetailTab } from './src/le-xref-detail-tab.js';

export const initLE = (targets, options) => {
    initLEext(document, targets, options)
};

export const getLEhelpInfo = () => {
    return getLEhelpInfoExt(LEtargets);
};

export { initXrefDetailTab };