export const getUserSetting = (storage, name, asNumber = false) => {

    let value = storage.getItem(name);

    if (value === undefined || value === null) return undefined;

    if (!asNumber) return value;

    const n = Number(value);
    return Number.isNaN(n) ? undefined : n;
};

export const setUserSetting = (storage, name, value, asNumber = false) => {

    if (value === undefined) {
        storage.removeItem(name);
        return;
    }

    if (asNumber) {
        const n = Number(value);
        if (Number.isNaN(n)) return;
        value = n;
    }

    storage.setItem(name, value);
};