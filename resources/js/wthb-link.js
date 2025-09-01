// included in LinkEnhancerModule.php headContent and wrapped in iife syntax; help_url is escaped in php
//<script>
//((options) => {
    const cfg = Object.assign({
        help_title: 'Webtrees manual', //i18n for link displayname
        help_url: '#',
        faicon: false, // prepend symbol to help link
        iswthb: true,  // is link external or a webtrees manual link?
        dotranslate: 0, //0=off, 1=user defined, 2=on
        help_tooltip: '', // i18n for tooltip with info about settings link; the I18N object is not included on admin page
        cfg_tooltip: '', // i18n for tooltip user setting link
    }, options);

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



    const toggleModal = (show = true, id = 'wthb-modal') => {
        if (show) {
            const myModal = new bootstrap.Modal(document.getElementById(id));
            myModal.show();
        } else {
            bootstrap.Modal.getInstance(document.getElementById(id)).hide();
        }
    };

    const insertWthbLink = (node) => { // prepend context help link to topmenu
        if (document.querySelector('li.nav-item.menu-wthb')) return;
        const topmenu = node ?? document.querySelector('ul.wt-user-menu, ul.nav.small');

        if (!topmenu) return;
        let fahtml = cfg.faicon ? '<i class="fa-solid fa-circle-question"></i> ' : '';
        // difference in styling between front- and backend: style="display: inline-block;" is missing on admin page
        topmenu.insertAdjacentHTML('afterbegin', `<li class="nav-item menu-wthb"><a id="wthb-link" class="nav-link" style="display: inline-block;" target="_blank" href="${cfg.help_url}">${fahtml}${cfg.help_title}</a></li>`);
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

    // extend topmenu
    insertWthbLink();
    const observer = new MutationObserver(insertWthbLinkCallback);
    observer.observe(document, { childList: true, subtree: true });

    //
    document.addEventListener('DOMContentLoaded', () => {
        // get language
        cfg.lang = $("html").attr("lang") ?? 'de';

        // only for webtrees manual links and i
        if (cfg.lang?.substr(0, 2).toLowerCase() == 'de' || !cfg.iswthb) return;

        let wthblink = $("#wthb-link");
        $(wthblink).on('click', (e) => { // help link handler
            if (!cfg.iswthb || !cfg.dotranslate) return; // open url directly

            if (cfg.dotranslate === 1) { // user defined behaviour
                // open settings dialog if no setting available
                let doTranslateUser = getWthbUserSetting(); //$('input[name=wthb-translate]:checked').val();
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

            const url = googleTranslate.replaceAll('%LANG%', cfg.lang).replaceAll('%URL%', encodeURIComponent(cfg.help_url));
            window.open(url, '_blank');
            e.preventDefault();
        });
        if (cfg.dotranslate !== 1) return; // no user setting 
        if (cfg.help_tooltip) $(wthblink).attr('title', cfg.help_tooltip);

        let wthbcfg = $('<a id="wthb-link-cfg" href="#"><i class="fa-solid fa-wrench fa-fw"></i>&nbsp;</a>')
            .hide()
            .on('click', () => toggleModal(true));
        if (cfg.cfg_tooltip) $(wthbcfg).attr('title', cfg.cfg_tooltip);

        // show/hide user settings link beside help link
        let timer = null;
        $(wthblink)
            .parent()
            .hover(
                function () {
                    timer = setTimeout(() => {
                        $(wthbcfg).show();
                    }, 2000);
                },
                function () {
                    clearTimeout(timer);
                    $(wthbcfg).hide();
                }
            );
        //$(wthblink).after(wthbcfg);
        $(wthblink).before(wthbcfg);

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
// }) ({help_title, help_url})</script >