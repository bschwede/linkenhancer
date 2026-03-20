// Markdown editor
import { installMDEExt } from "./src/mde";

export const installMDE = (options) => {
    installMDEExt(document, options)
}