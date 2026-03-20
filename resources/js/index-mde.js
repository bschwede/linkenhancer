// Markdown editor
import { initMDE } from "./src/mde";

export const installMDE = (options) => {
    initMDE(document, options)
}