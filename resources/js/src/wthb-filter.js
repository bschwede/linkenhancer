export const createSafeFilter = (document, expr) => { // a bit better than using eval - rollup doesn't like eval
    // https://developer.mozilla.org/de/docs/Web/JavaScript/Reference/Global_Objects/Function/Function

    const safeGlobals = {

        document,

        querySelectorAll: (sel) =>
            Array.from(document.querySelectorAll(sel)),

        Array,
        from: Array.from.bind(Array),

        nextElementSibling(node) {

            if (!(node instanceof Element)) {
                throw new TypeError("'nextElementSibling' called on non-Element");
            }

            return node.nextElementSibling;
        },

        querySelector(parent, sel) {

            if (!(parent instanceof Element)) {
                throw new TypeError("'querySelector' called on non-Element");
            }

            return parent.querySelector(sel);
        }
    };

    const fn = new Function(
        ...Object.keys(safeGlobals),
        `"use strict"; return (${expr})`
    );

    return () => {

        try {
            return fn(...Object.values(safeGlobals));
        } catch (e) {

            console.error("LE-mod wthb subcontext filter error:", expr, e);
            return null;
        }
    };
};