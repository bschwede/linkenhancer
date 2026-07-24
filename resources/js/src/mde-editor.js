import TinyMDE from "tiny-markdown-editor"
import { wrap } from "./mde-utils.js"
import { setupLineNumbers } from "./mde-line-numbers.js"
import { createGrammar } from "./mde-grammar.js"
import { createCommandBar } from "./mde-commandbar.js"

export const initEditor = (document, cfg, textarea) => {
    if (!textarea) return
    const modal = textarea.closest(".modal")

    if (modal && modal.id !== 'wt-ajax-modal') { // standard #wt-ajax-modal also works with instant binding, otherwise on shown.bs.modal
        // Wait until the modal init is complete, otherwise text might get lost (custom module ortsregister)
        let mdeditor = null
        const initModal = () => {
            mdeditor = mdeditor ?? bindEditor(document, cfg, textarea)
            if (mdeditor && mdeditor.textarea) {
                // ensure that the editor continues to run synchronously after it was closed/canceled before (ortsregister)
                mdeditor.setContent(mdeditor.textarea.value)
            }
        }

        // less flickering, if modal init is already completed at this point
        modal.addEventListener('show.bs.modal', initModal); 

        // call twice to be sure - on the visible modal everything should be initialized
        // downside: user sees editor replacement       
        modal.addEventListener('shown.bs.modal', initModal);
    } else {
        bindEditor(document, cfg, textarea)
    }
}

const bindEditor = (document, cfg, textarea) => {
    const id = `md-${textarea.id}`

    if (document.getElementById(id)) return null

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
        !textarea.closest(".modal")
    )
    return editor
}