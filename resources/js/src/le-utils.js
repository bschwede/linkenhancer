export const changeTagName = (document, element, newTag) => {

    const replacement = document.createElement(newTag);

    while (element.firstChild) {
        replacement.appendChild(element.firstChild);
    }

    for (let i = 0; i < element.attributes.length; i++) {
        const attr = element.attributes[i].cloneNode();
        replacement.attributes.setNamedItem(attr);
    }

    element.parentNode.replaceChild(replacement, element);

    return replacement;
};


export const createLink = (document) =>
    document.createElement("a");