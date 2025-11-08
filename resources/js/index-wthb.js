// Webtrees manual - link in top menu and subcontext topics
const getWthbCfg = () => {
    return {
        I18N: {
            help_title_wthb: 'Webtrees manual', //link displayname
            help_title_ext: 'Help',
            cfg_title: '', // tooltip user setting link
            searchntoc: 'Full text search / Table of contents', // title of submenu item of topmenu wthb-link
        },
        help_url: '#', // help url for top menu link
        faicon: false, // prepend symbol to top menu help link
        wiki_url: 'https://wiki.genealogy.net/',  // is link external or a webtrees manual link?
        dotranslate: 0, //0=off, 1=user defined, 2=on
        subcontext: [],
        modal_url: '', // url to action handler for wthb toc data and search engine form
        tocnsearch: true, // toggle for showing wthb help modal
    };
}

let WthbCfg = getWthbCfg();

let popovers = [];

const googleTranslate = "https://translate.google.com/translate?sl=de&tl=%LANG%&u=%URL%";

const wthb_user_setting = 'LEmod_wthb_translate';

const getWthbUserSetting = () => {
    let usersetting = localStorage.getItem(wthb_user_setting);
    if (usersetting !== undefined && usersetting !== null) {
        let cast_usersetting = Number(usersetting);
        usersetting = cast_usersetting === NaN ? undefined : cast_usersetting;
        return usersetting;
    } else {
        return undefined;
    }
};
const setWthbUserSetting = (value) => {
    if (value === undefined) {
        localStorage.removeItem(wthb_user_setting);
        return;
    }
    
    let cast_usersetting = Number(value);
    value = cast_usersetting === NaN ? undefined : cast_usersetting; 
    if (value !== undefined) {
        localStorage.setItem(wthb_user_setting, value);
    }
};

const isWthbLink = (url) => {
    return (!(typeof url === 'string' || url instanceof String)) ? false : url.startsWith(WthbCfg.wiki_url);
}

const toggleModal = (show = true, id = 'wthb-modal') => {
    if (show) {
        const myModal = new bootstrap.Modal(document.getElementById(id));
        myModal.show();
    } else {
        bootstrap.Modal.getInstance(document.getElementById(id)).hide();
    }
};

const getHelpTitle = (url) => isWthbLink(url) ? WthbCfg.I18N.help_title_wthb : WthbCfg.I18N.help_title_ext;


const insertWthbLink = (node) => { // prepend context help link to topmenu
    if (document.querySelector('li.nav-item.menu-wthb')) return;
    const topmenu = node ?? document.querySelector('ul.wt-user-menu, ul.nav.small');

    if (!topmenu) return;
    let fahtml = WthbCfg.faicon ? '<i class="fa-solid fa-circle-question"></i> ' : '';
    // difference in styling between front- and backend: style="display: inline-block;" is missing on admin page
    let help_title = getHelpTitle(WthbCfg.help_url);
    topmenu.insertAdjacentHTML('afterbegin', `<li class="nav-item menu-wthb"><a id="wthb-link" class="nav-link" style="display: inline-block;" target="_blank" href="${WthbCfg.help_url}">${fahtml}${help_title}</a></li>`);
};

const insertWthbLinkCallback = function (mutationsList, observer) {
    for (const mutation of mutationsList) {
        if (mutation.type === 'childList') {
            mutation.addedNodes.forEach(node => {
                if (node.nodeType === Node.ELEMENT_NODE) {
                    if (node.tagName === 'UL' && (node.classList.contains('wt-user-menu') || (node.classList.contains('nav') && node.classList.contains('small')))) {
                        insertWthbLink(node);
                    }
                }
            });
        }
    }
};

const insertWthbSubcontextLinks = (contexts) => {
    if (!Array.isArray(contexts) || contexts.length === 0) return;
    
    popovers = [];

    contexts.forEach((elem) => {
        let ctx = elem.ctx;
        let url = elem.url;
        if (!(ctx && url)) retur

        let node = null;
        let pos = 'top';
        ctx = ctx.trim();
        if (ctx.startsWith('{')) { // JSON object: {f:filter, e:JS, p:position} e or f needed, p optional
            try {
                let ctxobj = JSON.parse(ctx);
                node = jQuery(ctxobj?.e ? eval(ctxobj.e) : (ctxobj?.f ?? null));
                pos = ctxobj?.p ?? pos;
            } catch (e) {
                console.warn('LE-mod wthb subcontext:', ctx, e);
                return;
            }
        } else { // must be a filter expression
            node = jQuery(ctx);
        }
        if (jQuery.isEmptyObject(node) || node.length === 0) {
            return;
        }

        let poptrigger = jQuery('<span>', {
            class: 'popover-trigger',
            text: 'ⓘ',
        });
        node.append(poptrigger);

        let helptitle = getHelpTitle(url);
        let wthblink = jQuery(`<a href="${url}" target="_blank" class="stretched-link d-inline-block p-1 text-decoration-none"><i class="fa-solid fa-circle-question"></i> ${helptitle}</a>`);
        setWthbLinkClickHandler(wthblink);
        popovers.push(new window.bootstrap.Popover(poptrigger, { // poptrigger.popover() doesn`t work
            html: true,
            container: 'body',
            sanitize: false,
            placement: pos,
            trigger: 'focus hover',   // remains as long as the element is focused
            delay: { "show": 100, "hide": 2000 },
            content: wthblink,
        }));
    });

    popovers.forEach(el => {
        el._element.addEventListener('show.bs.popover', () => {
            // Disable popovers within group, except the current one
            popovers.forEach(otherEl => {
                if (otherEl !== el) {
                    otherEl.hide();
                }
            });
        });

        el._element.addEventListener('shown.bs.popover', () => {
            // Automatic fading after 5 seconds
            if (el.hideTimeout) clearTimeout(el.hideTimeout);
            el.hideTimeout = setTimeout(() => {
                el.hide();
            }, 5000);
        });

        el._element.addEventListener('hide.bs.popover', () => {
            // Delete timer if closed manually
            if (el.hideTimeout) {
                clearTimeout(el.hideTimeout);
                el.hideTimeout = null;
            }
        });
    }); 
    
}

const setWthbLinkClickHandler = (wthblink) => {
    $(wthblink).on('click', (e) => { // help link handler
        if (WthbCfg.lang?.substr(0, 2).toLowerCase() == 'de' || !isWthbLink($(wthblink).attr('href')) || !WthbCfg.dotranslate) return; // open url directly

        if (WthbCfg.dotranslate === 1) { // user defined behaviour
            // open settings dialog if no setting available
            let doTranslateUser = getWthbUserSetting();
            switch (doTranslateUser) {
                case undefined:
                    toggleModal(true);
                    $("#wthb-epilogue").show();
                    e.preventDefault();
                    return;

                case false:
                case 0:
                    return;
            }
        }

        const url = googleTranslate.replaceAll('%LANG%', WthbCfg.lang).replaceAll('%URL%', encodeURIComponent($(wthblink).attr('href')));
        window.open(url, '_blank');
        e.preventDefault();
    });    
}

const initWthb = (options) => {
    WthbCfg = Object.assign(getWthbCfg(), options);

    // extend topmenu
    insertWthbLink();
    const observer = new MutationObserver(insertWthbLinkCallback);
    observer.observe(document, { childList: true, subtree: true });

    //
    document.addEventListener('DOMContentLoaded', () => {
        insertWthbSubcontextLinks(WthbCfg.subcontext);

        let wthblink = window.jQuery("#wthb-link");
        if ($(wthblink).length === 0) { // not all pages have a top menu (e.g. note edit page)
            return;
        }
        // get language
        WthbCfg.lang = $("html").attr("lang") ?? 'de';
        
        // topmenu link "submenu"
        let popcontent = '';
        let wthbcfg = false;
        if (WthbCfg.tocnsearch) {
            popcontent += `<p><a class="wthbpopover" href="#" data-bs-backdrop="static" data-bs-toggle="modal" data-bs-target="#le-ajax-modal" data-wt-href="${WthbCfg.modal_url}">${WthbCfg.I18N.searchntoc}</a></p>`;
        }
        if (WthbCfg.lang?.substr(0, 2).toLowerCase() != 'de') {
            popcontent += `<p><a class="wthbpopover" id="wthb-link-cfg" href="#"><i class="fa-solid fa-wrench fa-fw"></i>&nbsp;${WthbCfg.I18N.cfg_title}</a></p>`;
            wthbcfg = true;
        }

        if (popcontent !== '') {
            let wthbpopover = new window.bootstrap.Popover(wthblink, {
                html: true,
                container: 'body',
                sanitize: false,
                placement: 'bottom',
                trigger: 'focus hover',   // remains as long as the element is focused
                delay: { "show": 100, "hide": 3000 },
                content: popcontent,
                title: WthbCfg.I18N.help_title_wthb,
            });
            wthbpopover._element.addEventListener('shown.bs.popover', () => {
                if (wthbcfg) {
                    let wthbcfg = $("#wthb-link-cfg").on('click', () => toggleModal(true));
                    if (WthbCfg.I18N.cfg_title) $(wthbcfg).attr('title', WthbCfg.I18N.cfg_title);
                }
                $('a.wthbpopover').each((idx, elem) => {
                    $(elem).on('click', () => wthbpopover.hide());
                });
            });
        }

        // translation settings only needed for non german language
        if (WthbCfg.lang?.substr(0, 2).toLowerCase() == 'de') return;

        setWthbLinkClickHandler(wthblink);

        if (WthbCfg.dotranslate !== 1) return; // no user setting 

        $('#wthb-modal').on('show.bs.modal', (e) => {
            $("#wthb-epilogue").hide(); // standard - should be only visible if user has not yet made decission for translation, because the dialog is opened automatically

            // set radio buttons
            $('input[name=wthb-translate]').prop('checked', false) //clear first
            let setting = getWthbUserSetting();
            if (setting !== undefined) {
                try {
                    $(`#wthb-translate-${setting}`).prop('checked', true);
                } catch (e) {}
            }
        });
        $('#wthb-modal .btn-primary').on('click', () => { //save setting
            const doClickHelplink = $("#wthb-epilogue").is(":visible");
            let doTranslateUser = $('input[name=wthb-translate]:checked').val();
            //TODO validate?!
            setWthbUserSetting(doTranslateUser);
            toggleModal(false);
            if (doClickHelplink) $("#wthb-link").click();
        });
    });
}

export { initWthb };