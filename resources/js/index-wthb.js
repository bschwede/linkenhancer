// Webtrees manual - link in top menu and subcontext topics
import { createWthb } from "./src/wthb.js";

const wthb = createWthb({
    document: window.document,
    window,
    bootstrap: window.bootstrap,
    jQuery: window.jQuery
});


const initWthb = (options) => {
    wthb.init(options);
}

const initWthbHelp = (searchEngines) => {
    wthb.initHelp(searchEngines);
}

export { initWthb, initWthbHelp };