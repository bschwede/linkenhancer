import { WTHB_USER_SETTING } from "./wthb-config.js";
import { setWthbLinkClickHandler } from "./wthb-logic.js";
import { getUserSetting, setUserSetting } from "./wthb-storage.js";

export const initHelp = (document, window, bootstrap, jQuery, cfg, searchengines) => {

    // table of contents
    const updateFilterCount = (vis, all) => {
        jQuery("#wthbtocfiltercnt").text(
            vis !== all ? `${vis} / ${all}` : all
        );
    };

    const tocitems = jQuery("span.item");

    updateFilterCount(tocitems.length, tocitems.length);

    jQuery("#wthbtocfilter").on("input", function () {
        const text = jQuery(this).val().toLowerCase();
        let visible = 0;

        tocitems.each((_, el) => {
            const match = jQuery(el).text().toLowerCase().includes(text);
            jQuery(el).toggle(match);
            if (match) visible++;
        });

        updateFilterCount(visible, tocitems.length);
    });

    // prepare wt manual links - prepend base url and bind click handler
    let wikiurl = cfg.wiki_url;
    wikiurl = wikiurl + (wikiurl.match(/\/$/) ? '' : '/');
    jQuery(".wthbtoc a").each((idx, elem) => {
        let href = jQuery(elem).attr('href') ?? '';
        if (!href.match(/^https?:\/\//)) {
            jQuery(elem).attr('href', wikiurl + href.replace(/^\/+/, ''));
            if (cfg.openInNewTab) {
                jQuery(elem).attr('target', '_blank');
            }
            setWthbLinkClickHandler(document, window, bootstrap, jQuery, cfg, elem);
        }
    });
    jQuery("a.gwlink").each(((idx, elem) => setWthbLinkClickHandler(document,window, bootstrap, jQuery, cfg, elem)));

    // populate select with toc section headings
    let tocselect = jQuery("#wthbtocheads");
    let tocheads = jQuery(".wthbtoc h2");
    jQuery(tocheads).each((idx, elem) => {
        jQuery(tocselect).append(jQuery('<option>', {
            value: idx,
            text: jQuery(elem).text()
        }));
    });
    jQuery(tocselect).on('change', function () {
        let idx = parseInt(this.value);
        if (!isNaN(idx) && idx < jQuery(tocheads).length) {
            jQuery(tocheads).get(idx).scrollIntoView();
            jQuery(tocselect).prop("selectedIndex", 0);
        }
    });

    jQuery().ready(() => jQuery(tocheads).get(0).scrollIntoView());

    // full-text search
    const setSEngineIcon = (value) => {
        let iconspan = jQuery('#sengineicon');
        jQuery(iconspan).attr('class', function (i, c) {
            return c.replace(/(^|\s)icon-\S+/g, '');
        });
        if (value !== -1) {
            let suffix = String(value).toLowerCase();
            jQuery(iconspan).addClass('icon-' + (suffix === 'genwiki' ? 'compgen' : suffix));
        }
    }

    let searchfilter = jQuery('#wthbsearchfilter');
    let sengine = jQuery('#wthbsearch');
    jQuery(sengine).prop("selectedIndex", 0);
    let lastsengine = getUserSetting(localStorage, WTHB_USER_SETTING.sengine);
    if (lastsengine !== undefined) {
        jQuery(sengine).val(lastsengine);
        setSEngineIcon(lastsengine);
    }

    const submitSearch = () => {
        let text = jQuery(searchfilter).val();
        let engine = jQuery(sengine).val();
        if (!text) {
            jQuery(searchfilter).focus();
            return;
        }
        if (engine == -1) {
            jQuery(sengine).focus();
            return
        }
        let url = searchengines[engine] ?? '';
        if (!url) return

        window.open(url + encodeURIComponent(text), '_blank');
    }

    jQuery(searchfilter).keypress(function (e) {
        let keycode = (e.keyCode ? e.keyCode : e.which);
        if (keycode === 13) {
            submitSearch();
        }
    });
    jQuery(sengine).on('change', function () {
        setSEngineIcon(this.value);
        if (this.value !== -1) {
            setUserSetting(localStorage, WTHB_USER_SETTING.sengine, String(this.value));
            submitSearch();
        }
    });
    jQuery('#wthbsearchsubmit').on('click', () => submitSearch());
};