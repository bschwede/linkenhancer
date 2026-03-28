export const i18nMixin = {
    I18N: null,  // Late init

    i18n(id) {
        const i18nObj = this.I18N;

        if (!i18nObj || typeof i18nObj !== 'object') {
            console.error('LE-mod - i18n: this.I18N missing');
            return id || '[no I18N]';
        }

        if (typeof id !== 'string') {
            console.warn('LE-mod - i18n: ID string expected', id);
            return '[invalid id]';
        }

        const translation = i18nObj[id];
        return translation !== undefined ? translation : id;
    }
};