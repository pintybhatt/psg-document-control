import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { enableAutoUnmount, mount } from '@vue/test-utils';
import UiButton from '../../resources/js/components/ui/UiButton.vue';
import TextInput from '../../resources/js/components/ui/TextInput.vue';
import SelectInput from '../../resources/js/components/ui/SelectInput.vue';
import TextareaInput from '../../resources/js/components/ui/TextareaInput.vue';
import CheckboxInput from '../../resources/js/components/ui/CheckboxInput.vue';
import FormField from '../../resources/js/components/ui/FormField.vue';
import StatusBadge from '../../resources/js/components/ui/StatusBadge.vue';
import LanguageSwitcher from '../../resources/js/components/LanguageSwitcher.vue';
import { createLocalization } from '../../resources/js/localization/index.js';

enableAutoUnmount(afterEach);
afterEach(() => document.body.replaceChildren());
beforeEach(() => localStorage.clear());

function render(component, options = {}) {
    return mount(component, { attachTo: document.body, global: { plugins: [createLocalization()] }, ...options });
}

describe('buttons', () => {
    it.each(['primary', 'secondary', 'danger'])('provides a native keyboard-operable %s button without submitting by default', async (variant) => {
        const wrapper = render(UiButton, { props: { variant }, slots: { default: 'Sample action' } });

        expect(wrapper.element.tagName).toBe('BUTTON');
        expect(wrapper.attributes('type')).toBe('button');
        expect(wrapper.text()).toBe('Sample action');
        wrapper.element.focus();
        expect(document.activeElement).toBe(wrapper.element);
        await wrapper.trigger('click');
        expect(wrapper.emitted('click')).toHaveLength(1);
    });

    it('blocks activation while disabled or loading and exposes the busy state', async () => {
        const wrapper = render(UiButton, { props: { disabled: true }, slots: { default: 'Save' } });

        await wrapper.trigger('click');
        expect(wrapper.emitted('click')).toBeUndefined();
        await wrapper.setProps({ disabled: false, loading: true });
        expect(wrapper.attributes('aria-busy')).toBe('true');
        expect(wrapper.element.disabled).toBe(true);
        expect(wrapper.text()).toContain('Loading…');
        await wrapper.trigger('click');
        expect(wrapper.emitted('click')).toBeUndefined();
        await wrapper.setProps({ loading: false, type: 'submit' });
        expect(wrapper.element.disabled).toBe(false);
        expect(wrapper.attributes('type')).toBe('submit');
    });
});

describe('form controls', () => {
    it.each([
        ['text', TextInput, 'input', {}, 'Example title'],
        ['select', SelectInput, 'select', { options: [{ value: '', label: 'Choose' }, { value: 'sample', label: 'Sample' }] }, 'sample'],
        ['textarea', TextareaInput, 'textarea', {}, 'Example notes'],
        ['checkbox', CheckboxInput, 'input', {}, true],
    ])('links the %s label, helper and validation error and updates its model', async (name, component, selector, extraProps, value) => {
        const wrapper = render(component, {
            props: { label: 'Sample field', required: true, helper: 'Helpful text', errorKey: 'uiValidationRequired', ...extraProps },
            attrs: { name: 'sample-field' },
        });
        const control = wrapper.get(selector);

        expect(wrapper.get('label').attributes('for')).toBe(control.attributes('id'));
        expect(control.attributes('name')).toBe('sample-field');
        expect(control.element.required).toBe(true);
        expect(control.attributes('aria-invalid')).toBe('true');
        const descriptions = control.attributes('aria-describedby').split(' ').map(id => document.getElementById(id).textContent);
        expect(descriptions).toEqual(['Helpful text', 'This field is required.']);
        await control.setValue(value);
        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual([value]);
        await wrapper.setProps({ errorKey: '' });
        expect(control.attributes('aria-invalid')).toBeUndefined();
        expect(wrapper.find('[role="alert"]').exists()).toBe(false);
    });

    it('passes native disabled and readonly attributes through to the input', () => {
        const wrapper = render(TextInput, { props: { label: 'Title' }, attrs: { disabled: true, readonly: true, autocomplete: 'off' } });

        expect(wrapper.get('input').element.disabled).toBe(true);
        expect(wrapper.get('input').element.readOnly).toBe(true);
        expect(wrapper.get('input').attributes('autocomplete')).toBe('off');
    });

    it('generates unique control IDs in one form and allows explicit IDs', () => {
        const wrapper = render({
            components: { TextInput },
            template: '<form><TextInput label="First" /><TextInput label="Second" /><TextInput id="custom-id" label="Third" /></form>',
        });
        const ids = wrapper.findAll('input').map(control => control.attributes('id'));

        expect(new Set(ids).size).toBe(3);
        expect(ids[2]).toBe('custom-id');
    });

    it('reactively translates validation errors and escapes server-provided error text', async () => {
        const wrapper = render({
            components: { TextInput, LanguageSwitcher },
            template: '<div><LanguageSwitcher /><TextInput label="Title" error-key="uiValidationRequired" /></div>',
        });

        await wrapper.get('select').setValue('lo');
        expect(wrapper.get('[role="alert"]').text()).toBe('ກະລຸນາປ້ອນຂໍ້ມູນໃນຊ່ອງນີ້.');

        const field = render(FormField, { props: { label: 'Sample', error: '<img src=x onerror=alert(1)>' } });
        expect(field.get('[role="alert"]').text()).toBe('<img src=x onerror=alert(1)>');
        expect(field.find('img').exists()).toBe(false);
    });
});

describe('status badges', () => {
    it.each([
        ['draft', 'Draft'], ['registered', 'Registered'], ['pending', 'Pending'], ['in-progress', 'In Progress'],
        ['closed', 'Closed'], ['effective', 'Effective'], ['superseded', 'Superseded'], ['cancelled', 'Cancelled'], ['unexpected', 'Unknown status'],
    ])('presents readable text for %s', (status, label) => {
        const wrapper = render(StatusBadge, { props: { status } });

        expect(wrapper.text()).toBe(label);
    });

    it('translates status labels after a language switch', async () => {
        const wrapper = render({
            components: { StatusBadge, LanguageSwitcher },
            template: '<div><LanguageSwitcher /><StatusBadge status="effective" /></div>',
        });

        await wrapper.get('select').setValue('lo');
        expect(wrapper.text()).toContain('ມີຜົນບັງຄັບໃຊ້');
    });
});
