// included in LinkEnhancerModule.php headContent and wrapped in iife syntax; help_url is escaped in php
//<script>
//((help_title, help_url) => {
    const fnwtlink = (node) => {
        if (document.querySelector('li.nav-item.menu-wthb')) return;
        const topmenu = node ?? document.querySelector('ul.wt-user-menu, ul.nav.small');

        if (!topmenu) return;

        topmenu.insertAdjacentHTML('afterbegin', `<li class="nav-item menu-wthb"><a class="nav-link" href="${help_url}"><i class="fa-solid fa-circle-question"></i> ${help_title}</a ></li>`);
    };
    const callback = function (mutationsList, observer) {
        for (const mutation of mutationsList) {
            if (mutation.type === 'childList') {
                mutation.addedNodes.forEach(node => {
                    if (node.nodeType === Node.ELEMENT_NODE) {
                        if (node.tagName === 'UL' && (node.classList.contains('wt-user-menu') || (node.classList.contains('nav') && node.classList.contains('small')))) {
                            fnwtlink(node);
                        }
                    }
                });
            }
        }
    };

    fnwtlink();
    const observer = new MutationObserver(callback);
    observer.observe(document, { childList: true, subtree: true });
// }) (help_title, help_url)</script >