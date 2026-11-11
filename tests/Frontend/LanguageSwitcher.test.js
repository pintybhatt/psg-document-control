import { afterEach, describe, expect, it } from 'vitest';
import { enableAutoUnmount, mount } from '@vue/test-utils';
import LanguageSwitcher from '../../resources/js/components/LanguageSwitcher.vue';
import { createLocalization } from '../../resources/js/localization/index.js';

enableAutoUnmount(afterEach);
afterEach(() => { document.body.replaceChildren(); localStorage.clear(); });

describe('language toggle switch', () => {
    it('slides between English and Lao with a flag on each side and an accessible radio group', async () => {
        const wrapper = mount(LanguageSwitcher, { attachTo: document.body, global: { plugins: [createLocalization()] } });
        const group = wrapper.get('[data-language-switcher]');
        const knob = () => wrapper.get('[data-language-knob]').classes();

        expect(group.attributes('role')).toBe('radiogroup');
        expect(group.attributes('aria-label')).toBe('Language');
        expect(wrapper.findAll('input[type="radio"]').map(input => input.element.value)).toEqual(['en', 'lo']);
        expect(wrapper.findAll('label').map(label => label.text())).toEqual(['ENEnglish', 'ລາວລາວ']);
        expect(wrapper.findAll('label svg')).toHaveLength(2);
        expect(wrapper.get('input:checked').element.value).toBe('en');
        expect(knob()).toContain('translate-x-0');

        await wrapper.get('input[value="lo"]').setValue(true);
        expect(document.documentElement.lang).toBe('lo');
        expect(localStorage.getItem('dcms.language')).toBe('lo');
        expect(wrapper.get('input:checked').element.value).toBe('lo');
        expect(knob()).toContain('translate-x-full');
        expect(group.attributes('aria-label')).toBe('ພາສາ');

        await wrapper.get('input[value="en"]').setValue(true);
        expect(document.documentElement.lang).toBe('en');
        expect(knob()).toContain('translate-x-0');
    });
});
