import { OBSERVED_TAGS } from './img-consts.js';


export const createScheduledHandler = handler => {

    const queue = new Set();
    let scheduled = false;

    const flush = () => {

        queue.forEach(node => handler(node));

        queue.clear();
        scheduled = false;
    };

    return node => {

        queue.add(node);

        if (!scheduled) {

            scheduled = true;

            requestAnimationFrame(flush);
        }
    };
};


export const initChildListObserver = (
    document,
    handler
) => {

    const schedule =
        createScheduledHandler(handler);

    const observer =
        new MutationObserver(mutations => {

            mutations.forEach(m => {

                if (m.type !== 'childList') return;

                m.addedNodes.forEach(node => {

                    if (!(node instanceof Element)) return;

                    if (
                        !OBSERVED_TAGS.includes(
                            node.tagName
                        )
                    ) return;

                    schedule(node);
                });
            });
        });

    observer.observe(
        document.body,
        {
            childList: true,
            subtree: true
        }
    );
};


export const initTdHObserver = (
    document,
    handler
) => {

    const schedule =
        createScheduledHandler(handler);

    const visibilityObserver =
        new MutationObserver(mutations => {

            mutations.forEach(m => {

                if (
                    m.type === 'attributes' &&
                    (
                        m.attributeName === 'style' ||
                        m.attributeName === 'class'
                    )
                ) {
                    schedule(m.target);
                }
            });
        });

    visibilityObserver.observe(
        document.body,
        {
            attributes: true,
            subtree: true,
            attributeFilter: ['style', 'class']
        }
    );
};