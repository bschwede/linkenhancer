export const setLink = (
    document,
    startlink,
    lastlink,
    href,
    title,
    classname,
    LEoptions,
    fragment
) => {
    
    let nextlink = lastlink ? document.createElement("a") : startlink;

    if (href) {

        nextlink.setAttribute("href", href);

        if (LEoptions.openInNewTab) {
            nextlink.setAttribute("target", '_blank');
        }

    } else {

        nextlink.onclick = e => {
            alert(nextlink.title ?? LEoptions.i18n('syntax error') + "!");
            e.preventDefault();
        };

        nextlink.classList.add('icon-error');
    }

    if (title) {

        nextlink.setAttribute("title", title);
        nextlink.setAttribute("aria-label", title);
    }

    if (classname) {

        const iconSpan = document.createElement("span");

        iconSpan.classList.add("linkicon");
        iconSpan.classList.add(classname);

        nextlink.appendChild(iconSpan);
    }

    //if (lastlink) lastlink.after(nextlink);
    if (nextlink !== startlink) {
        fragment.appendChild(nextlink)
    }

    return nextlink;
};