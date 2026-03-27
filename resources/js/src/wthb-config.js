import { i18nMixin } from './i18n-mixin.js';

export const getDefaultConfig = () => ({
    ...i18nMixin,
    I18N: {
        help_title_wthb: 'Webtrees manual',
        help_title_ext: 'Help',
        cfg_title: '',
        tocnsearch: 'Full-text search / Table of contents',
        wtcorehelp: 'webtrees help topics (included)',
        startpage: 'start page',
        admin_title: 'Link-Enhancer - Admin',
    },

    help_url: '#',
    faicon: false,
    wiki_url: 'https://wiki.genealogy.net/',
    wthb_url: 'https://wiki.genealogy.net/Webtrees_Handbuch',

    dotranslate: 0,
    doTranslateUser: undefined,
    subcontext: [],

    tocnsearch_url: '', // help is shown if not empty

    openInNewTab: true,
    splitNavlink: true,

    wtcorehelp_url: '', // help is shown if not empty

    linksJson: [],
    admin_url: ''
});


export const WTHB_USER_SETTING = {
    translate: 'LEmod_wthb_translate',
    sengine: 'LEmod_wthb_sengine',
}