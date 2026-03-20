// Enhanced links (XREF and external targets) in notes
import { initLEext, getLEhelpInfoExt, LEtargets } from './src/le.js';

export const initLE = (targets, options) => {
    initLEext(document, I18N, targets, options)
};

export const getLEhelpInfo = () => {
    getLEhelpInfoExt(LEtargets);
};