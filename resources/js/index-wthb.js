// Webtrees manual - link in top menu and subcontext topics
const getWthbCfg = () => {
    return {
        I18N: {
            help_title_wthb: 'Webtrees manual', //link displayname
            help_title_ext: 'Help',
            cfg_title: '', // tooltip user setting link
            searchntoc: 'Full-text search / Table of contents', // title of submenu item of topmenu wthb-link
        },
        help_url: '#', // help url for top menu link
        faicon: false, // prepend symbol to top menu help link
        wiki_url: 'https://wiki.genealogy.net/',  // is link external or a webtrees manual link?
        dotranslate: 0, //0=off, 1=user defined, 2=on
        subcontext: [],
        modal_url: '', // url to action handler for wthb toc data and search engine form
        tocnsearch: true, // toggle for showing wthb help modal
        openInNewTab: true,
        splitNavlink: true,
    };
}

let WthbCfg = getWthbCfg();

let popovers = [];

const is_touch_device = ('ontouchstart' in window) || (window.DocumentTouch && document instanceof DocumentTouch);

const googleTranslate = "https://translate.google.com/translate?sl=de&tl=%LANG%&u=%URL%";

const wthb_user_setting_translate = 'LEmod_wthb_translate';
const wthb_user_setting_sengine   = 'LEmod_wthb_sengine';

const getWthbUserSetting = (name, asnumber = false) => {
    let usersetting = localStorage.getItem(name);
    if (usersetting !== undefined && usersetting !== null) {
        if (asnumber) {
            let cast_usersetting = Number(usersetting);
            usersetting = cast_usersetting === NaN ? undefined : cast_usersetting;
        }
        return usersetting;
    } else {
        return undefined;
    }
};
const setWthbUserSetting = (name, value, asnumber = false) => {
    if (value === undefined) {
        localStorage.removeItem(name);
        return;
    }
    if (asnumber) {
        let cast_usersetting = Number(value);
        value = cast_usersetting === NaN ? undefined : cast_usersetting;
    }
    if (value !== undefined) {
        localStorage.setItem(name, value);
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

    let dropdown = '';
    let wthbcfg = false;
    if (WthbCfg.tocnsearch) {
        dropdown += `<a class="dropdown-item menu-wthb" role="menuitem" href="#" data-bs-backdrop="static" data-bs-toggle="modal" data-bs-target="#le-ajax-modal" data-wt-href="${WthbCfg.modal_url}">${WthbCfg.I18N.searchntoc}</a>`;
    }
    if (WthbCfg.lang?.substr(0, 2).toLowerCase() != 'de') {
        dropdown += `<a class="dropdown-item menu-wthb" role="menuitem" id="wthb-link-cfg" href="#"><i class="fa-solid fa-wrench fa-fw"></i>&nbsp;${WthbCfg.I18N.cfg_title}</a>`;
        wthbcfg = true;
    }

    //`<li class="nav-item menu-wthb"><a id="wthb-link" class="nav-link" style="display: inline-block;" ${target}href="${WthbCfg.help_url}">${fahtml}${help_title}</a></li>`
    const help_icon = '<i class="fa-solid fa-circle-question"></i> ';
    const navlink_icon = WthbCfg.faicon ? help_icon : '';
    const help_title = getHelpTitle(WthbCfg.help_url);
    
    let isSplitNavlink = WthbCfg.splitNavlink; // split nav link means: help link is a clickable top menu item and the trigger for the submenu is separated

    // help link is top menu item, if we have nothing other to display in a dropdown menu
    let link_class = 'nav-link';
    let link_attrs = ''; // difference in styling between front- and backend: style="display: inline-block;" is missing on admin page
    let link_text  = `${navlink_icon}${help_title}`;
    let navlink_text  = '<i class="fa-solid fa-bars"></i>';
    let navlink_class = '';
    let li_class = '';
    let dropdown_title = '';
    if (dropdown !== '') { // we have a dropdown
        if (!isSplitNavlink) {
            link_class = 'dropdown-item menu-wthb';
            link_attrs = ' role="menuitem"';
            link_text = `${help_icon}${help_title}`; // always visible question mark icon like in sub context popovers
            navlink_text = `${navlink_icon}${WthbCfg.I18N.help_title_wthb}`; // no distinction between webtrees manual and external help for the top menu item title 
        } else {
            link_class += ' nav-link-p';
            navlink_class = ' nav-link-p';
            dropdown_title = ` title="${WthbCfg.I18N.help_title_wthb}"`;
        }
    }

    // compose help link
    link_attrs += (WthbCfg.openInNewTab ? ' target="_blank"' : '');
    const wthblink = `<a id="wthb-link" class="${link_class}"${link_attrs} href="${WthbCfg.help_url}">${link_text}</a>`;

    // insert help link on right position
    let navlink = wthblink;
    if (dropdown !== '') {
        const navlink_dropdown = `<a href="#" class="nav-link dropdown-toggle${navlink_class}" data-bs-toggle="dropdown"${dropdown_title} role="button" aria-expanded="false">${navlink_text} <span class="caret"></span></a>`;                
        if (!isSplitNavlink) {
            dropdown = wthblink + dropdown;
            navlink = navlink_dropdown;
        } else {
            navlink = wthblink + navlink_dropdown;
            li_class = ' menu-wthb-p';
        }
        dropdown = `<div class="dropdown-menu dropdown-menu-end" role="menu">${dropdown}</div>`;
    }

    const html = `<li class="nav-item dropdown menu-wthb${li_class}">${navlink}${dropdown}</li>`
    topmenu.insertAdjacentHTML('afterbegin', html);
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

const onShownBsPopoverHideTimeout = (el, timersec = 5000) => {
    // Automatic fading after timersec seconds
    if (el.hideTimeout) clearTimeout(el.hideTimeout);
    el.hideTimeout = setTimeout(() => {
        el.hide();
    }, timersec);
}
const onHideBsPopoverHideTimout =  (el) => {
    // Delete timer if closed manually
    if (el.hideTimeout) {
        clearTimeout(el.hideTimeout);
        el.hideTimeout = null;
    }
}

const newPopover = (el, options) => new window.bootstrap.Popover(
        el,
        Object.assign({
            html: true,
            container: 'body',
            sanitize: false,
            placement: 'bottom',
            trigger: is_touch_device ? 'click' : 'focus hover',   // remains as long as the element is focused
            delay: { "show": 100, "hide": 3000 },
        }, options)
    );


const createSafeFilter = (filterExpr) => { // a bit better than using eval - rollup doesn't like eval
    // https://developer.mozilla.org/de/docs/Web/JavaScript/Reference/Global_Objects/Function/Function
    const safeGlobals = {
        document,
        querySelectorAll: (sel) => Array.from(document.querySelectorAll(sel)),
        find: Array.prototype.find.bind(Array.prototype),  // Bound for Arrays

        // DOM-traversal as function (with this binding)
        nextElementSibling: function (node) {
            if (!(node instanceof Element)) {
                throw new TypeError("'nextElementSibling' called on non-Element");
            }
            return node.nextElementSibling;
        },
        querySelector: function (parent, sel) {
            if (!(parent instanceof Element)) {
                throw new TypeError("'querySelector' called on non-Element");
            }
            return parent.querySelector(sel);
        },

        // helper functions
        Array,
        from: Array.from.bind(Array),

        // jQuery
        $: (sel) => Array.from(document.querySelectorAll(sel))
    };

    const fn = new Function(
        ...Object.keys(safeGlobals),
        `'use strict'; return (${filterExpr})`
    );

    return () => {
        try {
            return fn(...Object.values(safeGlobals));
        } catch (e) {
            console.error('LE-mod wthb subcontext filter error:', filterExpr);
            console.error(e);
            return null;
        }
    };
}


const insertWthbSubcontextLinks = (contexts) => {
    if (!Array.isArray(contexts) || contexts.length === 0) return;
    
    popovers = [];

    const target = (WthbCfg.openInNewTab ? 'target="_blank" ' : '');
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
                if (ctxobj?.e ?? null) {
                    const filterFn = createSafeFilter(ctxobj.e);
                    const result = filterFn();
                    node = jQuery(Array.isArray(result) ? result[0] : result);
                } else {
                    node = jQuery((ctxobj?.f ?? null));
                }               
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
        let wthblink = jQuery(`<a href="${url}" ${target}class="stretched-link d-inline-block p-1 text-decoration-none"><i class="fa-solid fa-circle-question"></i> ${helptitle}</a>`);
        setWthbLinkClickHandler(wthblink);
        popovers.push(newPopover(poptrigger, {
            placement: pos,
            content: wthblink
        })); // poptrigger.popover() doesn`t work
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
            onShownBsPopoverHideTimeout(el);
        });

        el._element.addEventListener('hide.bs.popover', () => {
            // Delete timer if closed manually
            onHideBsPopoverHideTimout(el);
        });
    }); 
    
}

const setWthbLinkClickHandler = (wthblink) => {
    $(wthblink).on('click', (e) => { // help link handler
        if (WthbCfg.lang?.substr(0, 2).toLowerCase() == 'de' || !isWthbLink($(wthblink).attr('href')) || !WthbCfg.dotranslate) return; // open url directly

        if (WthbCfg.dotranslate === 1) { // user defined behaviour
            // open settings dialog if no setting available
            let doTranslateUser = getWthbUserSetting(wthb_user_setting_translate, true);
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

    // get language
    WthbCfg.lang = document.documentElement.lang || 'de';

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
        
        // translation settings only needed for non german language
        if (WthbCfg.lang?.substr(0, 2).toLowerCase() == 'de') return;

        setWthbLinkClickHandler(wthblink);

        if (WthbCfg.dotranslate !== 1) return; // no user setting 

        let wthbcfg = $("#wthb-link-cfg").on('click', () => toggleModal(true));
        if (WthbCfg.I18N.cfg_title) $(wthbcfg).attr('title', WthbCfg.I18N.cfg_title);        

        $('#wthb-modal').on('show.bs.modal', (e) => {
            $("#wthb-epilogue").hide(); // standard - should be only visible if user has not yet made decission for translation, because the dialog is opened automatically

            // set radio buttons
            $('input[name=wthb-translate]').prop('checked', false) //clear first
            let setting = getWthbUserSetting(wthb_user_setting_translate, true);
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
            setWthbUserSetting(wthb_user_setting_translate, doTranslateUser, true);
            toggleModal(false);
            if (doClickHelplink) $("#wthb-link").click();
        });
    });
}

const initWthbHelp = (searchengines) => {
    const updateFilterCount = (visitems, allitems) => $("#wthbtocfiltercnt").text((visitems != allitems ? `${visitems} / ${allitems}` : allitems));

    // table of contents
    let tocitems = $("span.item");
    updateFilterCount($(tocitems).length, $(tocitems).length)
    let sbox = $("#wthbtocfilter");
    $(sbox).on('input', () => {
        let stext = $(sbox).val().toLowerCase();
        let allitems = 0;
        let visitems = 0;

        if (stext) {
            $(tocitems).each((idx, elem) => {
                allitems++;
                let text = $(elem).text().toLowerCase();
                if (text.indexOf(stext) === -1) { $(elem).hide(); } else { $(elem).show(); visitems++; }
            });
        } else {
            $(tocitems).show();
            allitems = $(tocitems).length;
            visitems = allitems;
        }
        updateFilterCount(visitems, allitems)
    });
    let wikiurl = WthbCfg.wiki_url ?? 'https://wiki.genealogy.net';
    wikiurl = wikiurl + (wikiurl.match(/\/$/) ? '' : '/');
    $(".wthbtoc a").each((idx, elem) => { 
        let href = $(elem).attr('href') ?? '';
        if (! href.match(/^https?:\/\//)) {
            $(elem).attr('href', wikiurl + href.replace(/^\/+/, ''));
            if (WthbCfg.openInNewTab) {
                $(elem).attr('target', '_blank');
            }
            setWthbLinkClickHandler(elem);
        }
    });
    $("a.gwlink").each(((idx, elem) => setWthbLinkClickHandler(elem)));

    let tocselect = $("#wthbtocheads");
    let tocheads = $(".wthbtoc h2");
    $(tocheads).each((idx, elem) => {
        $(tocselect).append($('<option>', {
            value: idx,
            text: $(elem).text()
        }));
    });
    $(tocselect).on('change', function () {
        let idx = parseInt(this.value);
        if (!isNaN(idx) && idx < $(tocheads).length) {
            $(tocheads).get(idx).scrollIntoView();
            $(tocselect).prop("selectedIndex", 0);
        }
    });

    $().ready(() => $(tocheads).get(0).scrollIntoView());

    // full-text search
    const setSEngineIcon = (value) => {
        let iconspan = $('#sengineicon');
        $(iconspan).attr('class', function (i, c) {
            return c.replace(/(^|\s)icon-\S+/g, '');
        });
        if (value !== -1) {
            let suffix = String(value).toLowerCase();
            $(iconspan).addClass('icon-' + (suffix === 'genwiki' ? 'compgen' : suffix));
        }
    }

    let searchfilter = $('#wthbsearchfilter');
    let sengine = $('#wthbsearch');
    $(sengine).prop("selectedIndex", 0);
    let lastsengine = getWthbUserSetting(wthb_user_setting_sengine);
    if (lastsengine !== undefined) {
        $(sengine).val(lastsengine);
        setSEngineIcon(lastsengine);
    }

    const submitSearch = () => {
        let text = $(searchfilter).val();
        let engine = $(sengine).val();
        if (!text) {
            $(searchfilter).focus();
            return;
        }
        if (engine == -1) {
            $(sengine).focus();
            return
        }
        let url = searchengines[engine] ?? '';
        if (!url) return

        window.open(url + encodeURIComponent(text), '_blank');
    }

    $(searchfilter).keypress(function (e) {
        let keycode = (e.keyCode ? e.keyCode : e.which);
        if (keycode === 13) {
            submitSearch();
        }
    });
    $(sengine).on('change', function () {
        setSEngineIcon(this.value);
        if (this.value !== -1) {
            setWthbUserSetting(wthb_user_setting_sengine, String(this.value));
            submitSearch();
        }
    });
    $('#wthbsearchsubmit').on('click', () => submitSearch());

}

export { initWthb, initWthbHelp };