// vanillajs-datepicker calls EventTarget.prototype.addEventListener.call(window, ...). In Vitest's jsdom
// environment the global `window` is not a jsdom EventTarget instance, so route those calls to its own methods.
for (const name of ['addEventListener', 'removeEventListener']) {
    const original = EventTarget.prototype[name];
    const forGlobal = globalThis[name];
    if (typeof forGlobal === 'function' && forGlobal !== original) {
        EventTarget.prototype[name] = function (...args) {
            return this === globalThis ? forGlobal.apply(globalThis, args) : original.apply(this, args);
        };
    }
}

// Node 25+ defines its own global localStorage (unusable without --localstorage-file), which hides jsdom's.
// Fall back to an in-memory Storage so tests behave the same on every Node version.
if (typeof globalThis.localStorage?.clear !== 'function') {
    const store = new Map();
    const storage = {
        getItem: key => (store.has(String(key)) ? store.get(String(key)) : null),
        setItem: (key, value) => { store.set(String(key), String(value)); },
        removeItem: key => { store.delete(String(key)); },
        clear: () => { store.clear(); },
        key: index => [...store.keys()][index] ?? null,
        get length() { return store.size; },
    };
    Object.defineProperty(globalThis, 'localStorage', { value: storage, configurable: true, writable: true });
}

// jsdom has no matchMedia; SweetAlert2 uses it for its success icon animation.
if (typeof window.matchMedia !== 'function') {
    window.matchMedia = query => ({
        matches: false, media: query, onchange: null,
        addListener() {}, removeListener() {}, addEventListener() {}, removeEventListener() {}, dispatchEvent() { return false; },
    });
}
