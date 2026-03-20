export function wrap(el, wrapper = document.createElement("div")) { //https://stackoverflow.com/questions/6838104/pure-javascript-method-to-wrap-content-in-a-div
    el.parentNode.insertBefore(wrapper, el)
    return wrapper.appendChild(el)
}


export function debounceFrame(fn) {
    let scheduled = false

    return (...args) => {
        if (scheduled) return
        scheduled = true

        requestAnimationFrame(() => {
            scheduled = false
            fn(...args)
        })
    }
}