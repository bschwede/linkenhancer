import { getMdeConfig } from "./mde-config.js"
import { initEditors } from "./mde-editor.js"
import { observeEditors } from "./mde-observer.js"

export function installMDEExt(document, options = {}) {

    const cfg =
        typeof options === "object"
            ? { ...getMdeConfig(), ...options }
            : getMdeConfig()

    cfg.I18N = I18N // temp.

    initEditors(document, cfg)

    observeEditors(document, initEditors, cfg)
}