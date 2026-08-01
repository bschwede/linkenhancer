import { WTHB_USER_SETTING } from "./wthb-config.js";
import { setWthbLinkClickHandler } from "./wthb-logic.js";
import { getUserSetting, setUserSetting } from "./wthb-storage.js";

export const initHelp = (document, window, bootstrap, cfg, searchengines) => {

    // table of contents
    const updateFilterCount = (vis, all) => {
        const el = document.getElementById("wthbtocfiltercnt");
        if (el) {
            el.textContent = vis !== all ? `${vis} / ${all}` : all;
        }
    };

    const tocitems = document.querySelectorAll("span.item");

    updateFilterCount(tocitems.length, tocitems.length);

    const tocfilter = document.getElementById("wthbtocfilter");
    if (tocfilter) {
        tocfilter.addEventListener("input", function () {
            const text = this.value.toLowerCase();
            let visible = 0;

            tocitems.forEach((el) => {
                const match = el.textContent.toLowerCase().includes(text);
                el.style.display = match ? '' : 'none';
                if (match) visible++;
            });

            updateFilterCount(visible, tocitems.length);
        });
    }

    // prepare wt manual links - prepend base url and bind click handler
    prepareWthbLinks(document, window, bootstrap, cfg, ".wthbtoc a"); 

    const gwlinks = document.querySelectorAll("a.gwlink");
    gwlinks.forEach((elem) => setWthbLinkClickHandler(document, window, bootstrap, cfg, elem));

    // populate select with toc section headings
    const tocselect = document.getElementById("wthbtocheads");
    const tocheads = document.querySelectorAll(".wthbtoc h2");
    
    if (tocselect && tocheads.length > 0) {
        tocheads.forEach((elem, idx) => {
            const option = document.createElement('option');
            option.value = idx;
            option.textContent = elem.textContent;
            tocselect.appendChild(option);
        });

        tocselect.addEventListener('change', function () {
            let idx = parseInt(this.value);
            if (!isNaN(idx) && idx < tocheads.length) {
                tocheads[idx].scrollIntoView();
                tocselect.selectedIndex = 0;
            }
        });
    }

    // full-text search
    const setSEngineIcon = (value) => {
        const iconspan = document.getElementById('sengineicon');
        if (!iconspan) return;
        iconspan.className = iconspan.className.replace(/(^|\s)icon-\S+/g, '');
        if (value !== -1) {
            let suffix = String(value).toLowerCase();
            iconspan.classList.add('icon-' + (suffix === 'genwiki' ? 'compgen' : suffix));
        }
    }

    const searchfilter = document.getElementById('wthbsearchfilter');
    const sengine = document.getElementById('wthbsearch');
    if (sengine) {
        sengine.selectedIndex = 0;
    }
    let lastsengine = getUserSetting(localStorage, WTHB_USER_SETTING.sengine);
    if (lastsengine !== undefined) {
        if (sengine) sengine.value = lastsengine;
        setSEngineIcon(lastsengine);
    }

    const submitSearch = () => {
        let text = searchfilter ? searchfilter.value : '';
        let engine = sengine ? sengine.value : -1;
        if (!text) {
            searchfilter?.focus();
            return;
        }
        if (engine == -1) {
            sengine?.focus();
            return
        }
        let url = searchengines[engine] ?? '';
        if (!url) return

        window.open(url + encodeURIComponent(text), '_blank');
    }

    if (searchfilter) {
        searchfilter.addEventListener('keypress', function (e) {
            let keycode = (e.keyCode ? e.keyCode : e.which);
            if (keycode === 13) {
                submitSearch();
            }
        });
    }
    if (sengine) {
        sengine.addEventListener('change', function () {
            setSEngineIcon(this.value);
            if (this.value !== -1) {
                setUserSetting(localStorage, WTHB_USER_SETTING.sengine, String(this.value));
                submitSearch();
            }
        });
    }
    const searchsubmit = document.getElementById('wthbsearchsubmit');
    if (searchsubmit) {
        searchsubmit.addEventListener('click', () => submitSearch());
    }

    //https://stackoverflow.com/questions/2180326/jquery-event-model-and-preventing-duplicate-handlers
    const leAjaxModal = document.getElementById('wt-ajax-modal');
    if (leAjaxModal && tocheads.length > 0) {
        const handleModalShown = function () {
            tocheads[0].scrollIntoView();
            this.scrollTop = 0;
            const firstInput = this.querySelector('input[type="text"], input:not([type])');
            if (firstInput) firstInput.focus();
        }
        const handleModalHide = function () {
            this.removeEventListener('shown.bs.modal.wthb', handleModalShown);
            this.removeEventListener('hide.bs.modal.wthb', handleModalHide);
        }
        leAjaxModal.addEventListener('shown.bs.modal.wthb', handleModalShown);
        leAjaxModal.addEventListener('hide.bs.modal.wthb', handleModalHide);
    }    
};

export const prepareWthbLinks = (document, window, bootstrap, cfg, aselector) => {
    // prepare wt manual links - prepend base url and bind click handler
    let wikiurl = cfg.wiki_url;
    wikiurl = wikiurl + (wikiurl.match(/\/$/) ? '' : '/');
    const elements = document.querySelectorAll(aselector);
    elements.forEach((elem) => {
        let href = elem.getAttribute('href') || '';
        if (!href.match(/^https?:\/\//)) {
            elem.setAttribute('href', wikiurl + href.replace(/^\/+/, ''));
            if (cfg.openInNewTab) {
                elem.setAttribute('target', '_blank');
            }
            setWthbLinkClickHandler(document, window, bootstrap, cfg, elem);
        }
    });    
}
