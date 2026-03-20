export function createGrammar(cfg) {
    // HINT striketrough is natively supported by tiny-mde - so syntax highlighting is still available if extension is disabled

    let grammar = {}

    if (!cfg.ext) return grammar

    if (cfg.ext_fn) {
        Object.assign(grammar, {

            footnote: { // should be a block rule
                regexp: /^\[\^([^\]]+)\]:/,
                replacement:
                    '<span class="TMMark TMMark_TMLink">[^</span>' +
                    '<span class="TMLink TMFootnoteLabel">$1</span>' +
                    '<span class="TMMark TMMark_TMLink">]:</span>'
            },

            footnoteref: {
                regexp: /^\[\^([^\]]+)\](?<!:)/,
                replacement:
                    '<span class="TMMark TMMark_TMLink">[^</span>' +
                    '<span class="TMLink TMFootnoteRefLabel">$1</span>' +
                    '<span class="TMMark TMMark_TMLink">]</span>'
            }

        })
    }

    if (cfg.ext_mark) {
        grammar.highlight = {
            regexp: /^(==)([^=]+)(==)/,
            replacement:
                '<span class="TMMark">$1</span>' +
                '<span class="TMHighlight">$2</span>' +
                '<span class="TMMark">$3</span>'
        }
    }

    return grammar
}