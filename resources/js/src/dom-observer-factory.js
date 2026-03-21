/*
+ Queue
+ WeakSet
  https://developer.mozilla.org/de/docs/Web/JavaScript/Reference/Global_Objects/WeakSet
+ rAF batching - rAF = requestAnimationFrame
  https://dev.to/tawe/requestanimationframe-explained-why-your-ui-feels-laggy-and-how-to-fix-it-3ep2
+ DOM MutationObserver
+ Matching
+ Collecting
+ Error Handling
+ Test Hooks (flushNow)
*/
export function createDomObserver({

    root = document.body,
    match,
    collect,
    process,
    useWeakSet = true,
    initialScan = false,
    attributeFilter

}) {

    const queue = new Set()
    const processed = useWeakSet ? new WeakSet() : null

    let scheduled = false

    const flush = () => {

        queue.forEach(el => {

            if (processed && processed.has(el)) return

            processed?.add(el)

            try {
                process(el)
            } catch (e) {
                console.error("LE-mod - Observer process error:", e)
            }

        })

        queue.clear()

        scheduled = false
    }

    const schedule = () => {

        if (scheduled) return

        scheduled = true

        requestAnimationFrame(flush)
    }

    const collectFromNode = (node) => {

        if (!(node instanceof Element)) return

        // direct match
        if (match?.(node)) {
            queue.add(node)
        }

        // subtree
        const found = collect?.(node)
        if (found) {
            for (const el of found) {
                queue.add(el)
            }
        }
    }

    let observer = null

    if (Array.isArray(attributeFilter)) { // attribute

        observer = new MutationObserver(mutations => {

            for (const m of mutations) {

                if (m.type !== "attributes" || !attributeFilter.includes(m.attributeName)) continue

                queue.add(m.target)

            }

            if (queue.size > 0) {
                schedule()
            }
        })

        observer.observe(root, {
            attributes: true,
            subtree: true,
            attributeFilter: attributeFilter
        })

    } else { // childList
        if (initialScan) {
            collectFromNode(root)
            flush()
        }

        observer = new MutationObserver(mutations => {

            for (const m of mutations) {

                if (m.type !== "childList") continue

                for (const node of m.addedNodes) {
                    collectFromNode(node)
                }
            }

            if (queue.size > 0) {
                schedule()
            }
        })

        observer.observe(root, {
            childList: true,
            subtree: true
        })
    }

    return {

        observer,

        disconnect() {
            observer.disconnect()
        },

        flushNow() {
            flush()
        }

    }
}