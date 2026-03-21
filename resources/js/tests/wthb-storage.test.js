import { expect } from "chai";
import { getUserSetting, setUserSetting }
    from "../src/wthb-storage.js";

describe("wthb-storage.js", () => {

    let storage;

    beforeEach(() => {

        storage = new Map();

        storage.getItem = k => storage.get(k);
        storage.setItem = (k, v) => storage.set(k, v);
        storage.removeItem = k => storage.delete(k);
    });

    it("stores value", () => {

        setUserSetting(storage, "a", "1");

        expect(storage.get("a")).to.equal("1");
    });

    it("removes value when undefined", () => {

        storage.set("a", "1");

        setUserSetting(storage, "a", undefined);

        expect(storage.get("a")).to.equal(undefined);
    });

    it("reads stored value", () => {

        storage.set("x", "123");

        const r = getUserSetting(storage, "x");

        expect(r).to.equal("123");
    });

    it("casts number when requested", () => {

        storage.set("n", "42");

        const r = getUserSetting(storage, "n", true);

        expect(r).to.equal(42);
    });

});
