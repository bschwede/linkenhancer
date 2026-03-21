import TinyMDE from "tiny-markdown-editor"
import { wrap } from "./mde-utils.js"
import { setupLineNumbers } from "./mde-line-numbers.js"
import { createGrammar } from "./mde-grammar.js"
import { createCommandBar } from "./mde-commandbar.js"

export const initEditor = (document, cfg, textarea) => {
    const id = `md-${textarea.id}`

    if (document.getElementById(id)) return

    const editor = new TinyMDE.Editor({
        element: textarea,
        customInlineGrammar: createGrammar(cfg)
    })
    const txtId = `txt-${textarea.id}`;

    editor.e.id = id

    wrap(editor.e) //#2

    // Workaround - input help/OSK
    const oskElem = document.querySelector(`.wt-osk-trigger[data-wt-id="${textarea.id}"]`)
    if (oskElem) {
        oskElem.dataset.wtId = txtId;
        const txtNode = document.createElement("input");
        txtNode.id = txtId;
        txtNode.type = 'text';
        //txtNode.addEventListener('input', (e) => editor.paste(e.target.value)); //inserted always to the end
        oskElem.insertAdjacentElement('afterend', txtNode);
    }

    setupLineNumbers(editor.e)

    createCommandBar(
        editor,
        cfg,
        !textarea.closest("#wt-ajax-modal")
    )
}