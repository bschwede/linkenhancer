import { getMdeConfig } from "./mde-config.js"
import { initEditor } from "./mde-editor.js"
import { createDomObserver } from "./dom-observer-factory.js" 

export function initMDE(document, options = {}) {

    const cfg =
        typeof options === "object"
            ? { ...getMdeConfig(), ...options }
            : getMdeConfig()


    createDomObserver({

        root: document.body,

        match: node =>
            node.tagName === "TEXTAREA",

        collect: node =>
            node.querySelectorAll?.(
                "textarea[id$='NOTE'], textarea[id$='note']" +
                (cfg.todo ? ", textarea[id$='_TODO']" : "")
            ),

        process: textarea =>
            initEditor(document, cfg, textarea),

        initialScan: true
    })    
}