// Webtrees manual - link in top menu and subcontext topics
import { createWthb } from "./src/wthb.js";

const wthb = createWthb({
    document: window.document,
    window,
    bootstrap: window.bootstrap
});


const initWthb = (options) => {
    wthb.init(options);
}

const initWthbHelp = (searchEngines) => { // see resources/views/help-wthb.phtml
    wthb.initHelp(searchEngines);
}

const initWtHelp = (aselector) => { // see resources/views/help-wt-helptext.phtml
    wthb.initWtHelp(aselector);
}


export { initWthb, initWthbHelp, initWtHelp };