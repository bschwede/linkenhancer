import { debounceFrame } from "./mde-utils.js"

export function observeEditors(document, initEditors, cfg) {

    const schedule = debounceFrame(() => {
        initEditors(document, cfg)
    })

    const observer = new MutationObserver(mutations => {

        for (const m of mutations) {

            if (m.type !== "childList") continue

            for (const node of m.addedNodes) {

                if (node.nodeType !== 1) continue //Node.ELEMENT_NODE

                if (
                    node.tagName === "TEXTAREA" ||
                    node.querySelector?.("textarea")
                ) {
                    schedule()
                    break
                }
            }
        }
    })

    observer.observe(document.body, {
        childList: true,
        subtree: true
    })

    return observer
}