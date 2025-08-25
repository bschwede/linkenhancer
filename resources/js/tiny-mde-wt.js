import TinyMDE from 'tiny-markdown-editor';

function getLinkSupportCfg() {
    return {
        href: true,
        src: true
    }
}

let linkSupport = getLinkSupportCfg();


//https://stackoverflow.com/questions/6838104/pure-javascript-method-to-wrap-content-in-a-div
const wrap = (toWrap, wrapper) => {
    wrapper = wrapper || document.createElement('div');
    toWrap.parentNode.insertBefore(wrapper, toWrap);
    return wrapper.appendChild(toWrap);
};

function setupDynamicLineNumbers(editorEl) {
    if (!editorEl) return;

    // one-time creation of the invisible width measurer
    let measurer = document.getElementById('line-number-measurer');
    if (!measurer) {
        measurer = document.createElement('div');
        measurer.id = 'line-number-measurer';
        Object.assign(measurer.style, {
            position: 'absolute',
            visibility: 'hidden',
            whiteSpace: 'nowrap',
            fontSize: '0.75em',
            fontFamily: 'inherit',
            padding: '2px 6px',
        });
        document.body.appendChild(measurer);
    }

    // update line numbering & dynamic padding
    function updateLineNumbers() {
        const paras = editorEl.querySelectorAll('div[class^="TM"]');
        let maxLineNum = 0;

        paras.forEach((el, index) => {
            const num = index + 1;
            el.setAttribute('data-line-num-view', num);
            if (num > maxLineNum) maxLineNum = num;
        });

        // measure the width of the largest number
        measurer.textContent = maxLineNum;
        const measuredWidth = measurer.getBoundingClientRect().width;
        const finalWidth = Math.ceil(measuredWidth + 12); // Puffer + Padding

        // set dynamic padding
        paras.forEach(el => el.style.paddingLeft = `${finalWidth}px`);
        editorEl.style.setProperty('--line-num-width', `${finalWidth}px`);
    }

    // initial update
    updateLineNumbers();

    // Observer that listens to DOM changes in the editor
    const observer = new MutationObserver(() => updateLineNumbers());

    observer.observe(editorEl, {
        childList: true,
        subtree: true,
    });
}


function createMDECommandbar(editor) {
    const barDiv = document.createElement("div");
    const cmdBar = new TinyMDE.CommandBar({
        element: barDiv,
        editor: editor,
        commands: [
            { name: 'bold', title: I18N['bold']},
            { name: 'italic', title: I18N['italic']},
            { name: 'code', title: I18N['format as code']},
            '|',
            { name: "h1", title: I18N['Level 1 heading'] },
            { name: "ul", title: I18N['Bulleted list'] },
            { name: "ol", title: I18N['Numbered list'] },
            '|',
            {
                name: 'insertLink',
                title: I18N['Insert link'],
                action: editor => {
                    let dest = window.prompt(I18N['Link destination']);
                    if (!dest && linkSupport.href) dest = "#@wt=i@@";
                    editor.wrapSelection('[', `](${dest})`);
                }
            },
            { 
                name: 'insertImage', 
                title: I18N['Insert image'],
                action: editor => {
                    let dest = linkSupport.src ? "#@id=@@" : '';
                    editor.wrapSelection('![', `](${dest})`);
                }
            },
            {
                name: 'insertTable',
                title: I18N['Insert table'],
                action: editor => {
                    const getTabRow = (cols, cellvalue) => ['', Array.from({ length: cols }, () => cellvalue).join('|'), ''].join('|');
                    let colsNrows = window.prompt(I18N['queryTableCnR']);
                    if (!colsNrows) return;
                    let cols = 3;
                    let rows = 2;
                    let markup = '';
                    let m = colsNrows.trim().match(/^(\d+)[, ]+(\d+)/)
                    if (m) {
                        cols = m[1];
                        rows = m[2];
                    }
                    rows = rows < 2 ? 2 : rows;
                    for (let i=0; i <= rows; i++) { // one more for second row with dashes
                        markup += getTabRow(cols, (i == 1 ? '-' : ' ').repeat(3)) + '\n';
                    }
                    editor.paste(markup);
                },
                innerHTML: '<b>T</b>'
            },

            { name: 'hr', title: I18N['hr'] },
            '|',
            { name: 'undo', title: I18N['Undo'] },
            { name: 'redo', title: I18N['Redo'] },
            '|',
            {
                name: 'modHelp',
                title: I18N['Help'],
                innerHTML: `<a href="#" data-bs-backdrop="static" data-bs-toggle="modal" data-bs-target="#wt-ajax-modal" data-wt-href="${LEhelp}"><b style="padding:0 3px;">?</b></a>`,
            },
        ]
    });
    editor.e.parentNode.insertBefore(barDiv, editor.e);

    return cmdBar;
}

function installMDE(cfg) {
    linkSupport = (typeof cfg == 'object' && cfg !== null ? Object.assign(getLinkSupportCfg(), cfg) : getLinkSupportCfg());
    document.querySelectorAll("textarea[id$='NOTE'], textarea[id$='NOTE-CONC']").forEach((elem) => {
        let editor = new TinyMDE.Editor({ element: elem });
        let edId = `md-${elem.id}`;
        let txtId = `txt-${elem.id}`;
        editor.e.id = edId;

        wrap(editor.e); //#2
        //editor.e.classList.add('form-control');

        // Workaround - input help/OSK
        const oskElem = document.querySelector(`.wt-osk-trigger[data-wt-id="${elem.id}"]`)
        if (oskElem) {
            oskElem.dataset.wtId = txtId;
            const txtNode = document.createElement("input");
            txtNode.id = txtId;
            txtNode.type = 'text';
            //txtNode.addEventListener('input', (e) => editor.paste(e.target.value)); //inserted always to the end
            oskElem.insertAdjacentElement('afterend', txtNode);
        }
        setupDynamicLineNumbers(editor.e);
        
        createMDECommandbar(editor);
    });
}

export { installMDE };