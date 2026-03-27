import { i18nMixin } from './i18n-mixin.js';

export function getMdeConfig() {
    return {
        ...i18nMixin,        
        href: true,
        src: true,
        ext: true,        // extension master switch
        ext_mark: true,   // highlight extension
        ext_fn: true,     // footnote extension
        ext_strike: true, // striketrough extension
        todo: true, // with wt 2.2.5 _TODO text fields also support markdown
        helpmd_url: '',
    }
}