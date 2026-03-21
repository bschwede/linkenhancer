export function setupLineNumbers(editorEl) {

    if (!editorEl) return

    let measurer =
        document.getElementById("line-number-measurer")

    // one-time creation of the invisible width measurer
    if (!measurer) {
        measurer = document.createElement("div")
        measurer.id = "line-number-measurer"

        Object.assign(measurer.style, {
            position: "absolute",
            visibility: "hidden",
            whiteSpace: "nowrap",
            fontSize: "0.75em",
            fontFamily: "inherit",
            padding: "2px 6px",
        })

        document.body.appendChild(measurer)
    }

    const update = () => { // update line numbering & dynamic padding

        const paras =
            editorEl.querySelectorAll('div[class^="TM"]')

        let max = paras.length

        paras.forEach((el, i) =>
            el.setAttribute("data-line-num-view", i + 1)
        )

        // measure the width of the largest number
        measurer.textContent = max

        const width =
            Math.ceil(measurer.getBoundingClientRect().width + 12)

        paras.forEach(el =>
            el.style.paddingLeft = `${width}px`
        )

        editorEl.style.setProperty(
            "--line-num-width",
            `${width}px`
        )
    }

    update()

    const observer = new MutationObserver(
        update
    )

    observer.observe(editorEl, {
        childList: true,
        subtree: true,
    })
}