export const setLink = (
    document,
    link,
    lastlink,
    href,
    title,
    classname,
    LEoptions
) => {

    if (href) {

        link.setAttribute("href", href);

        if (LEoptions.openInNewTab) {
            link.setAttribute("target", '_blank');
        }

    } else {

        link.onclick = e => {
            alert(link.title ?? LEoptions.I18N['syntax error'] + "!");
            e.preventDefault();
        };

        link.classList.add('icon-error');
    }

    if (title) {

        link.setAttribute("title", title);
        link.setAttribute("aria-label", title);
    }

    if (classname) {

        const iconSpan = document.createElement("span");

        iconSpan.classList.add("linkicon");
        iconSpan.classList.add(classname);

        link.appendChild(iconSpan);
    }

    if (lastlink) lastlink.after(link);

    return link;
};