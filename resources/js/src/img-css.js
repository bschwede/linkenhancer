export const findCssRule = (document, selector, property) => { // find a specific css rule and property

    for (const sheet of document.styleSheets) {

        try {
            for (const rule of sheet.cssRules) {
                if (
                    rule instanceof CSSStyleRule &&
                    rule.selectorText === selector
                ) {
                    return rule.style.getPropertyValue(property);
                }
            }
        } catch {
            // ignore CORS errors for external stylesheets
            continue;
        }
    }

    return null;
};


export const parseCssValue = (
    cssValue,
    relativeToVh
) => { // cell height control - retreive height value as float

    if (!cssValue) return Infinity;

    if (cssValue.includes('vh')) {
        return parseFloat(cssValue) / 100 * relativeToVh;
    }

    if (cssValue.includes('px')) {
        return parseFloat(cssValue);
    }

    return Infinity;
};