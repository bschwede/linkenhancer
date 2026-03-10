export const addClickIfNone = (el, handler) => { // only add one click handler

    if (el.getAttribute('data-click-listener') === 'true') {
        return;
    }

    el.setAttribute('data-click-listener', 'true');
    el.addEventListener('click', handler);
};


export const escapeHtmlAttribute = (document, str) => {

    const div = document.createElement('div');
    div.textContent = str;

    return div.innerHTML;
};


export const gotoTop = (
    window,
    document,
    refElement = null
) => { // scroll to top of window or ref element

    if (!refElement) {
        window.scrollTo({
            top: 0,
            left: 0,
            behavior: 'smooth'
        });
        return;
    }

    const walker = document.createTreeWalker(
        refElement,
        NodeFilter.SHOW_ELEMENT,
        {
            acceptNode(node) {

                const el = node;

                return (
                    el.offsetParent !== null &&
                    el.getClientRects().length > 0
                )
                    ? NodeFilter.FILTER_ACCEPT
                    : NodeFilter.FILTER_SKIP;
            }
        }
    );

    const firstVisible = walker.nextNode();

    if (firstVisible) {

        firstVisible.scrollIntoView({
            behavior: 'smooth',
            block: 'start',
            inline: 'nearest'
        });

    } else {
        window.scrollTo({
            top: 0,
            left: 0,
            behavior: 'smooth'
        });
    }
};
