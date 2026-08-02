import { getDefaultConfig, WTHB_USER_SETTING } from "./wthb-config.js";
import { buildMenuHtml, insertMenu } from "./wthb-menu.js";
import { insertSubcontextLinks } from "./wthb-subcontext.js";
import { setWthbLinkClickHandler, toggleModal } from "./wthb-logic.js";
import { initHelp, prepareWthbLinks } from "./wthb-help.js";
import { getUserSetting, setUserSetting } from "./wthb-storage.js";

export function createWthb(env) {

    let bootstrapRef = null;
    let _env = env;

    const getBootstrap = () => {
        if (!bootstrapRef) {
            bootstrapRef = (_env && _env.bootstrap) || (typeof window !== 'undefined' ? window.bootstrap : null);
        }
        return bootstrapRef;
    };

    const { document, window } = env;

    let cfg = getDefaultConfig();

    const init = (options = {}) => {

        cfg = Object.assign(getDefaultConfig(), options);

        cfg.lang = document.documentElement.lang || "de";
        cfg.doTranslateUser = getUserSetting(localStorage, WTHB_USER_SETTING.translate, true);

        const html = buildMenuHtml(cfg, document.location.href);
        insertMenu(document, html);
        const observer = new MutationObserver((mutationsList, observer) => {
            for (const mutation of mutationsList) {
                if (mutation.type === 'childList') {
                    mutation.addedNodes.forEach(node => {
                        if (node.nodeType === Node.ELEMENT_NODE) {
                            if (node.tagName === 'UL' && (node.classList.contains('wt-user-menu') || (node.classList.contains('nav') && node.classList.contains('small')))) {
                                insertMenu(document, html);;
                            }
                        }
                    });
                }
            }
        });
        observer.observe(document, { childList: true, subtree: true });


        document.addEventListener('DOMContentLoaded', () => {
            insertSubcontextLinks(
                document,
                window,
                getBootstrap(),
                cfg
            );

            bindEvents();
        });
    };

    const bindEvents = () => {

        let wthblink = document.getElementById("wthb-link");
        if (!wthblink) { // not all pages have a top menu (e.g. note edit page)
            return;
        }

        // translation settings only needed for non german language
        if (cfg.lang?.substr(0, 2).toLowerCase() == 'de') return;

        setWthbLinkClickHandler(document,window, getBootstrap(),cfg, wthblink);

        if (cfg.dotranslate !== 1) return; // no user setting 

        let wthbcfg = document.getElementById("wthb-link-cfg");
        if (wthbcfg) {
            wthbcfg.addEventListener('click', () => toggleModal(document, getBootstrap(), true));
            if (cfg.i18n('cfg_title')) wthbcfg.setAttribute('title', cfg.i18n('cfg_title'));
        }

        const wthbModal = document.getElementById('wthb-modal');
        if (wthbModal) {
            wthbModal.addEventListener('show.bs.modal', (e) => {
                const epilogue = document.getElementById("wthb-epilogue");
                if (epilogue) epilogue.style.display = 'none'; // standard - should be only visible if user has not yet made decission for translation, because the dialog is opened automatically

                // set radio buttons
                const radios = wthbModal.querySelectorAll('input[name=wthb-translate]');
                radios.forEach(r => r.checked = false); //clear first
                let setting = getUserSetting(localStorage, WTHB_USER_SETTING.translate, true);
                if (setting !== undefined) {
                    try {
                        const radio = document.getElementById(`wthb-translate-${setting}`);
                        if (radio) radio.checked = true;
                    } catch (e) { }
                }
            });
        }

        const btnPrimary = wthbModal?.querySelector('.btn-primary');
        if (btnPrimary) {
            btnPrimary.addEventListener('click', () => { //save setting
                const epilogue = document.getElementById("wthb-epilogue");
                const isEpilogueVisible = epilogue && epilogue.style.display !== 'none' && epilogue.offsetParent !== null;
                const checkedRadio = wthbModal?.querySelector('input[name=wthb-translate]:checked');
                let doTranslateUser = checkedRadio ? checkedRadio.value : undefined;
                setUserSetting(localStorage, WTHB_USER_SETTING.translate, doTranslateUser, true);
                cfg.doTranslateUser = getUserSetting(localStorage, WTHB_USER_SETTING.translate, true); // used in setWthbLinkClickHandler
                toggleModal(document, getBootstrap(), false);
                if (isEpilogueVisible) wthblink.click();
            });
        }
    };

    return {

        init,

        initHelp: (searchengines) => // webtrees manual toc and search
            initHelp(document, window, getBootstrap(), cfg, searchengines),

        initWtHelp: (aselector) => prepareWthbLinks(document, window, getBootstrap(), cfg, aselector) // webtrees core help topics
    };
}
