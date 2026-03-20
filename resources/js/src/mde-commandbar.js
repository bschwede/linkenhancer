import TinyMDE from "tiny-markdown-editor"

export function createCommandBar(editor, cfg, showHelp) {

    const el = document.createElement("div")

    let commands = [

        { name: "bold", title: cfg.I18N.bold },
        { name: "italic", title: cfg.I18N.italic },

        ...(cfg.ext && cfg.ext_strike
            ? [{ name: "strikethrough", title: cfg.I18N.strikethrough }]
            : []),

        { name: "code", title: cfg.I18N["format as code"] },
    ];

    if (cfg.ext && cfg.ext_mark) { // HighlightExtension is shipped with CommonMark 2.8.0 and available in webtrees 2.2.5
        commands.push(
            {
                name: 'highlight',
                title: cfg.I18N['highlight'],
                action: editor => {
                    if (editor.isInlineFormattingAllowed()) editor.wrapSelection("==", "==");
                },
                enabled: editor => editor.isInlineFormattingAllowed() ? false : null,
                //material-symbols:format-ink-highlighter-sharp
                innerHTML: '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="currentColor" d="M2 24v-4h20v4zm1.5-6l3.15-3.15l-.75-.725V12.7L10.6 8l5.4 5.425l-4.7 4.675H9.9l-.75-.75l-.65.65zM12 6.575l5.425-5.4l5.4 5.425l-5.4 5.4z"/></svg>',
            }
        );
    }    

    commands.push(
        "|",

        { name: "h1", title: cfg.I18N["Level 1 heading"] },
        { name: "ul", title: cfg.I18N["Bulleted list"] },
        { name: "ol", title: cfg.I18N["Numbered list"] },
        { name: "blockquote", title: I18N['quote'] },

        "|",

        {
            name: "insertLink",
            title: cfg.I18N["Insert link"],
            action: e => {
                let dest = prompt(cfg.I18N["Link destination"])
                if (!dest && cfg.href) dest = "#@wt=i@@"
                e.wrapSelection("[", `](${dest})`)
            }
        },

        {
            name: 'insertImage',
            title: I18N['Insert image'],
            action: editor => {
                let dest = MdeCfg.src ? "#@id=@@" : '';
                editor.wrapSelection('![', `](${dest})`);
            }
        },
        {
            name: 'insertTable',
            title: cfg.I18N['Insert table'],
            action: editor => {
                const getTabRow = (cols, cellvalue) => ['', Array.from({ length: cols }, () => cellvalue).join('|'), ''].join('|');
                let colsNrows = window.prompt(cfg.I18N['queryTableCnR']);
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
                for (let i = 0; i <= rows; i++) { // one more for second row with dashes
                    markup += getTabRow(cols, (i == 1 ? '-' : ' ').repeat(3)) + '\n';
                }
                editor.paste(markup);
            },
            //material-symbols:table-outline-sharp
            innerHTML: '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="currentColor" d="M3 21V3h18v18zm8-6H5v4h6zm2 0v4h6v-4zm-2-2V9H5v4zm2 0h6V9h-6zM5 7h14V5H5z"/></svg>'
        },
        { name: 'hr', title: cfg.I18N['hr'] },
        '|',
        { name: 'undo', title: cfg.I18N['Undo'] },
        { name: 'redo', title: cfg.I18N['Redo'] },        
    );

    if (showHelp) {
        commands.push(
            '|',
            {
                name: 'modHelp',
                title: cfg.I18N['Help'],
                innerHTML: `<a href="#" data-bs-backdrop="static" data-bs-toggle="modal" data-bs-target="#le-ajax-modal" data-wt-href="${LEhelp}"><b style="padding:0 3px;">?</b></a>`,
            }
        );
    }

    const bar = new TinyMDE.CommandBar({
        element: el,
        editor,
        commands
    })

    editor.e.parentNode.insertBefore(el, editor.e)

    return bar
}