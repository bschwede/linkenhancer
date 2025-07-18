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
                    let dest = window.prompt('Link destination');
                    if (!dest) dest = "#@wt=i@@+dia&wpde=&gov=&osm=";
                    editor.wrapSelection('[', `](${dest})`);
                }
            },
            { name: 'insertImage', title: I18N['Insert image'] },
            '|',
            { name: 'undo', title: I18N['Undo'] },
            { name: 'redo', title: I18N['Redo'] },
            '|',
            {
                name: 'mdHelp',
                title: 'Howto Markdown',
                innerHTML: '<small>MD</small>', //https://www.markdownguide.org/assets/images/markdown-mark-white.svg
                action: editor => window.open('https://www.markdownguide.org/basic-syntax/', '_blank')
            },
            {
                name: 'moreInfoCommonMark',
                title: 'More information about CommonMark (parser used by webtrees)',
                innerHTML: '<small>CM</small>',
                action: editor => window.open('https://commonmark.thephpleague.com/', '_blank')
            },
            {
                name: 'moreInfoTinyMDE',
                title: 'More information about TinyMDE (javascript editor)',
                innerHTML: '<b>?</b>',
                action: editor => window.open('https://github.com/jefago/tiny-markdown-editor', '_blank')
            }
        ]
    });
    editor.e.parentNode.insertBefore(barDiv, editor.e);

    return cmdBar;
}

function installMDE() {
    document.querySelectorAll("textarea[id$='NOTE']").forEach((elem) => {
        let editor = new TinyMDE.Editor({ element: elem });
        let edId = `md-${elem.id}`;
        let txtId = `txt-${elem.id}`;
        editor.e.id = edId;
        //editor.e.classList.add('form-control');

        // Workaround - Eingabehilfe
        const oskElem = document.querySelector(`.wt-osk-trigger[data-wt-id="${elem.id}"]`)
        if (oskElem) {
            oskElem.dataset.wtId = txtId;
            const txtNode = document.createElement("input");
            txtNode.id = txtId;
            txtNode.type = 'text';
            oskElem.insertAdjacentElement('afterend', txtNode);
        }
        setupDynamicLineNumbers(editor.e);
        
        createMDECommandbar(editor);
    });
}