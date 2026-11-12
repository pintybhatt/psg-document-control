import { afterEach, describe, expect, it } from 'vitest';
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import $ from 'jquery';
import { ref } from 'vue';
import SelectInput from '../../resources/js/components/ui/SelectInput.vue';
import { createLocalization } from '../../resources/js/localization/index.js';

enableAutoUnmount(afterEach);
afterEach(() => { document.body.replaceChildren(); localStorage.clear(); });
const options = [{ value: '', label: 'Choose' }, { value: 'a', label: 'Alpha' }, { value: 'b', label: 'Bravo' }];

function render(initial = '') {
    return mount({
        components: { SelectInput },
        setup: () => ({ value: ref(initial), options: ref(options) }),
        template: '<SelectInput v-model="value" label="Example" :options="options" />',
    }, { attachTo: document.body, global: { plugins: [createLocalization()] } });
}

describe('Select2 dropdowns', () => {
    it('enhances the native select and keeps v-model and the Select2 display in sync both ways', async () => {
        const wrapper = render('a');
        await flushPromises();
        const select = wrapper.get('select');
        const rendered = () => wrapper.get('.select2-selection__rendered').text();

        expect(select.classes()).toContain('select2-hidden-accessible');
        expect(rendered()).toBe('Alpha');

        $(select.element).val('b').trigger('change');
        $(select.element).trigger({ type: 'select2:select', params: { data: { id: 'b' } } });
        await flushPromises();
        expect(wrapper.vm.value).toBe('b');
        expect(rendered()).toBe('Bravo');

        wrapper.vm.value = 'a';
        await flushPromises();
        expect(rendered()).toBe('Alpha');

        await select.setValue('');
        await flushPromises();
        expect(wrapper.vm.value).toBe('');
        expect(rendered()).toBe('Choose');
    });


    it('removes the Select2 widget when the field unmounts', async () => {
        const wrapper = render();
        await flushPromises();
        expect(document.querySelector('.select2-container')).not.toBeNull();
        wrapper.unmount();
        expect(document.querySelector('.select2-container')).toBeNull();
    });
});
