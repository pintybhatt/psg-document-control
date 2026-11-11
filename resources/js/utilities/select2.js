import $ from 'jquery';
import select2Factory from 'select2';
import { nextTick, onBeforeUnmount, watch } from 'vue';

// select2's CommonJS build exports a factory that installs $.fn.select2.
if (typeof $.fn.select2 !== 'function') {
    select2Factory(window, $);
}

/**
 * Enhances a native <select> with Select2 while the <select> stays the source of truth:
 * Vue's v-model keeps listening to native change events, and Select2's display follows.
 * `element` may appear later (v-if); `sources` are reactive getters that refresh the display.
 */
export function useSelect2(element, { sources = [], placeholder = '', searchThreshold = 8, selectionClass = '', dropdownClass = '', width = '100%', config = {} } = {}) {
    let $select;

    function destroy() {
        if ($select?.data('select2')) {
            $select.off('.vue');
            $select.select2('destroy');
        }
        $select = undefined;
    }

    function init(select) {
        $select = $(select);
        $select.select2({
            width,
            placeholder: placeholder || undefined,
            minimumResultsForSearch: searchThreshold,
            dropdownParent: $(document.body),
            selectionCssClass: ['dcms-select2', selectionClass].filter(Boolean).join(' '),
            dropdownCssClass: ['dcms-select2-dropdown', dropdownClass].filter(Boolean).join(' '),
            ...config,
        });
        // Select2 announces changes through jQuery only; forward them as native events for Vue.
        $select.on('select2:select.vue select2:unselect.vue', () => {
            select.dispatchEvent(new Event('change', { bubbles: true }));
        });
    }

    watch(element, (select) => {
        destroy();
        if (select) { init(select); }
    }, { immediate: true, flush: 'post' });

    const refresh = async () => {
        await nextTick();
        $select?.trigger('change.select2');
    };
    if (sources.length) {
        watch(sources, refresh, { deep: true, flush: 'post' });
    }

    onBeforeUnmount(destroy);

    return { refresh };
}
