// jsdom has no matchMedia; SweetAlert2 uses it for its success icon animation.
if (typeof window.matchMedia !== 'function') {
    window.matchMedia = query => ({
        matches: false, media: query, onchange: null,
        addListener() {}, removeListener() {}, addEventListener() {}, removeEventListener() {}, dispatchEvent() { return false; },
    });
}
