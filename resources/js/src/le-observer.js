export const createScheduledHandler = handler => { // debounce scheduling

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



export const observeDomLinks = (
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

                    if (node.tagName === 'A') {

                        schedule(node);

                    } else {

                        node.querySelectorAll?.("a")
                            .forEach(schedule);
                    }
                });

            });

        });

    observer.observe(
        document.body,
        { childList: true, subtree: true }
    );
};