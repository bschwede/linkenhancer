import { isWthbLink, getHelpTitle } from "./wthb-logic.js";

export const buildMenuHtml = (cfg, locationHref) => {

    let dropdown = "";

    // include link to webtrees manual start page, if context link is external
    if (!isWthbLink(cfg.help_url, cfg.wiki_url)) {
        let target = (cfg.openInNewTab ? ' target="_blank"' : '');
        dropdown += `
        <a class="dropdown-item menu-wthb"${target}
            id="wthb-link-sp"
            href="${cfg.wthb_url}">
           ${cfg.i18n('help_title_wthb')} - ${cfg.i18n('startpage')}
        </a>`;
    }

    if (cfg.tocnsearch_url) { // webtrees table of contents and search
        dropdown += `
        <a class="dropdown-item menu-wthb"
            role="menuitem" 
            id="wthb-link-toc"
            href="#"
            data-bs-backdrop="static"
            data-bs-toggle="modal"
            data-bs-target="#le-ajax-modal"
            data-wt-href="${cfg.tocnsearch_url}">
            ${cfg.i18n('tocnsearch')}
        </a>`;
    }

    if (cfg.dotranslate === 1 && cfg.lang?.substr(0, 2).toLowerCase() != 'de') { // 
        dropdown += `
        <a class="dropdown-item menu-wthb"
            role="menuitem" id="wthb-link-cfg" 
            href="#">
            <i class="fa-solid fa-wrench fa-fw"></i>&nbsp;${cfg.i18n('cfg_title')}
        </a>`;
    }

    if (cfg.wtcorehelp_url) { // overview of help topic included in webtrees
        dropdown += (dropdown !== '' || !cfg.splitNavlink ? '<hr>' : '') + `
        <a class="dropdown-item menu-wthb"
            role="menuitem"
            id="wthb-link-core"
            href="#"
            data-bs-backdrop="static"
            data-bs-toggle="modal"
            data-bs-target="#le-ajax-modal"
            data-wt-href="${cfg.wtcorehelp_url}">
            ${cfg.i18n('wtcorehelp')}
        </a>`;
    }

    // additional help links at the end of the submenu
    if ((cfg.linksJson ?? false) && cfg.linksJson !== '') {
        let links = null;
        try {
            links = JSON.parse(cfg.linksJson);
        } catch (e) {
            console.warn('LEMod - wthb links JSON is invalid', e);
            links = null;
        }
        if (Array.isArray(links)) {
            let linkshtml = '';
            links.forEach((link) => {
                let title = link.title ?? null;
                let user_i18n = link?.i18n?.[cfg.lang];
                title = (title && user_i18n ? user_i18n : title);
                let url = link.url ?? null;
                let cname = (link.class ?? false ? ` ${link.class}` : '');
                let target = (link.self ?? false ? '_self' : '_blank');
                linkshtml += (title && url ? `<a class="dropdown-item menu-wthb-external${cname}" role="menuitem" target="${target}" href="${url}">${title}</a>` : '');
            });
            dropdown += (linkshtml != '' && (dropdown !== '' || !cfg.splitNavlink) ? '<hr>' : '') + linkshtml;
        }
    }

    // admin link if not admin page itself
    if (cfg.admin_url && !locationHref.startsWith(cfg.admin_url)) {
        dropdown += (dropdown !== '' || !cfg.splitNavlink ? '<hr>' : '') + `
        <a class="dropdown-item menu-wthb"
            role="menuitem"
            id="le-admin-link"
            href="${cfg.admin_url}">
            <i class="fa-solid fa-wrench fa-fw"></i>&nbsp;${cfg.i18n('admin_title')}
        </a>`;
    }

    const help_icon = '<i class="fa-solid fa-circle-question"></i> ';
    const navlink_icon = cfg.faicon ? help_icon : '';
    const help_title = getHelpTitle(cfg.help_url, cfg);

    // help link is top menu item, if we have nothing other to display in a dropdown menu
    let link_class = 'nav-link';
    let link_attrs = ''; // difference in styling between front- and backend: style="display: inline-block;" is missing on admin page
    let link_text = `${navlink_icon}${help_title}`;
    let navlink_text = '<i class="fa-solid fa-bars"></i>';
    let navlink_class = '';
    let li_class = '';
    let dropdown_title = '';
    if (dropdown !== '') { // we have a dropdown
        if (!cfg.splitNavlink) {
            link_class = 'dropdown-item menu-wthb';
            link_attrs = ' role="menuitem"';
            link_text = `${help_icon}${help_title}`; // always visible question mark icon like in sub context popovers
            navlink_text = `${navlink_icon}${cfg.i18n('help_title_wthb')}`; // no distinction between webtrees manual and external help for the top menu item title 
        } else {
            link_class += ' nav-link-p';
            navlink_class = ' nav-link-p';
            dropdown_title = ` title="${cfg.i18n('help_title_wthb')}"`;
        }
    }

    // compose help link
    link_attrs += (cfg.openInNewTab ? ' target="_blank"' : '');
    const wthblink = `<a id="wthb-link" class="${link_class}"${link_attrs} href="${cfg.help_url}">${link_text}</a>`;

    // insert help link on right position
    let navlink = wthblink;
    if (dropdown !== '') {
        const navlink_dropdown = `<a href="#" class="nav-link dropdown-toggle${navlink_class}" data-bs-toggle="dropdown"${dropdown_title} role="button" aria-expanded="false">${navlink_text} <span class="caret"></span></a>`;
        if (!cfg.splitNavlink) {
            dropdown = wthblink + dropdown;
            navlink = navlink_dropdown;
        } else {
            navlink = wthblink + navlink_dropdown;
            li_class = ' menu-wthb-p';
        }
        dropdown = `<div class="dropdown-menu dropdown-menu-end" role="menu">${dropdown}</div>`;
    }

    return `<li class="nav-item dropdown menu-wthb${li_class}">${navlink}${dropdown}</li>`;
};


export const insertMenu = (document, html) => {

    if (document.querySelector("li.nav-item.menu-wthb")) return;

    const topmenu = document.querySelector("ul.wt-user-menu, ul.nav.small");

    if (!topmenu) return;

    topmenu.insertAdjacentHTML("afterbegin", html);
};