import { getDefaultConfig, WTHB_USER_SETTING } from "./wthb-config.js";
import { buildMenuHtml, insertMenu } from "./wthb-menu.js";
import { insertSubcontextLinks } from "./wthb-subcontext.js";
import { setWthbLinkClickHandler, toggleModal } from "./wthb-logic.js";
import { initHelp } from "./wthb-help.js";
import { getUserSetting, setUserSetting } from "./wthb-storage.js";

export function createWthb(env) {

    //const { document, window, bootstrap, jQuery } = env; // if js is included in head, bootstrap and jQuery aren't loaded yet, so those two objects are null
    const { document, window } = env; // inclusion in the header has a better timing, otherwise if included in body the top menu item is flickering

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
                bootstrap, // on doc loaded vendor modules are initialized 
                jQuery,
                cfg
            );

            bindEvents();
        });
    };

    const bindEvents = () => {

        let wthblink = jQuery("#wthb-link");
        if (jQuery(wthblink).length === 0) { // not all pages have a top menu (e.g. note edit page)
            return;
        }

        // translation settings only needed for non german language
        if (cfg.lang?.substr(0, 2).toLowerCase() == 'de') return;

        setWthbLinkClickHandler(document,window, bootstrap, jQuery,cfg, wthblink);

        if (cfg.dotranslate !== 1) return; // no user setting 

        let wthbcfg = jQuery("#wthb-link-cfg").on('click', () => toggleModal(document, bootstrap, true));
        if (cfg.I18N.cfg_title) $(wthbcfg).attr('title', cfg.I18N.cfg_title);

        jQuery('#wthb-modal').on('show.bs.modal', (e) => {
            jQuery("#wthb-epilogue").hide(); // standard - should be only visible if user has not yet made decission for translation, because the dialog is opened automatically

            // set radio buttons
            jQuery('input[name=wthb-translate]').prop('checked', false) //clear first
            let setting = getUserSetting(localStorage, WTHB_USER_SETTING.translate, true);
            if (setting !== undefined) {
                try {
                    jQuery(`#wthb-translate-${setting}`).prop('checked', true);
                } catch (e) { }
            }
        });
        jQuery('#wthb-modal .btn-primary').on('click', () => { //save setting
            const doClickHelplink = jQuery("#wthb-epilogue").is(":visible");
            let doTranslateUser = jQuery('input[name=wthb-translate]:checked').val();
            setUserSetting(localStorage, WTHB_USER_SETTING.translate, doTranslateUser, true);
            cfg.doTranslateUser = getUserSetting(localStorage, WTHB_USER_SETTING.translate, true); // used in setWthbLinkClickHandler
            toggleModal(document, bootstrap, false);
            if (doClickHelplink) jQuery("#wthb-link").click();
        });
    };

    return {

        init,

        initHelp: (searchengines) =>
            initHelp(document, window, bootstrap, jQuery, cfg, searchengines)
    };
}