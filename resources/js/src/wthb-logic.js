export const isWthbLink = (url, wikiUrl) => {

    if (typeof url !== 'string') return false;

    return url.startsWith(wikiUrl);
};

export const getHelpTitle = (url, cfg) => {

    return isWthbLink(url, cfg.wiki_url)
        ? cfg.i18n('help_title_wthb')
        : cfg.i18n('help_title_ext');
};

export const buildTranslateUrl = (url, lang) => {

    const tpl = "https://translate.google.com/translate?sl=de&tl=%LANG%&u=%URL%";

    return tpl
        .replace('%LANG%', lang)
        .replace('%URL%', encodeURIComponent(url));
};

export const setWthbLinkClickHandler = (
        document,
        window,
        bootstrap,
        jQuery,
        cfg,
        wthblink) => 
    {
        jQuery(wthblink).on('click', (e) => { // help link handler
            if (cfg.lang?.substr(0, 2).toLowerCase() == 'de' || 
                !isWthbLink(jQuery(wthblink).attr('href'), cfg.wiki_url) || 
                !cfg.dotranslate) return; // open url directly

            if (cfg.dotranslate === 1) { // user defined behaviour
                // open settings dialog if no setting available
                switch (cfg.doTranslateUser) {
                    case undefined:
                        toggleModal(document, bootstrap, true);
                        jQuery("#wthb-epilogue").show();
                        e.preventDefault();
                        return;

                    case false:
                    case 0:
                        return;
                }
            }

            const url = buildTranslateUrl(jQuery(wthblink).attr('href'), cfg.lang) 
            window.open(url, '_blank');
            e.preventDefault();
        });
};


export const toggleModal = (document, bootstrap, show = true, id = 'wthb-modal') => {
    if (show) {
        const myModal = new bootstrap.Modal(document.getElementById(id));
        myModal.show();
    } else {
        bootstrap.Modal.getInstance(document.getElementById(id)).hide();
    }
};