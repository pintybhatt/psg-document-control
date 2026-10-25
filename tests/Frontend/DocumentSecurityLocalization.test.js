import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { enableAutoUnmount, mount } from '@vue/test-utils';
import LanguageSwitcher from '../../resources/js/components/LanguageSwitcher.vue';
import { createLocalization, useLocalization } from '../../resources/js/localization/index.js';

enableAutoUnmount(afterEach);
beforeEach(() => localStorage.clear());
afterEach(() => document.body.replaceChildren());

function render(keys) {
    return mount({
        components: { LanguageSwitcher },
        setup() { return { ...useLocalization(), keys }; },
        template: '<div><LanguageSwitcher /><p v-for="key in keys" :key="key">{{ t(key) }}</p></div>',
    }, { attachTo: document.body, global: { plugins: [createLocalization()] } });
}

describe('document security terminology', () => {
    it('switches all three classifications and their descriptions between English and Lao', async () => {
        const classifications = ['normal', 'confidential', 'highly_confidential'];
        const keys = classifications.flatMap(value => [`confidentiality-${value}`, `confidentiality-${value}-description`]);
        const wrapper = render(keys);
        expect(wrapper.findAll('p').filter((_, index) => index % 2 === 0).map(item => item.text())).toEqual(['Normal', 'Confidential', 'Highly Confidential']);
        expect(wrapper.text()).toContain('operation-specific document allowlist');
        await wrapper.get('select[aria-label="Language"]').setValue('lo');
        expect(wrapper.findAll('p').filter((_, index) => index % 2 === 0).map(item => item.text())).toEqual(['ປົກກະຕິ', 'ລັບ', 'ລັບສູງ']);
        expect(wrapper.findAll('p').every(item => item.text().length > 0 && !item.text().startsWith('confidentiality-'))).toBe(true);
    });

    it('keeps independent access operations and clearance states translated in both languages', async () => {
        const keys = ['documentOperation-metadata', 'documentOperation-preview', 'documentOperation-download', 'clearance', 'clearanceExpired', 'clearanceInactive', 'clearanceRevoked', 'documentAccessDenied'];
        const wrapper = render(keys);
        expect(wrapper.text()).toContain('Download attachments');
        expect(wrapper.text()).toContain('Expired clearance');
        await wrapper.get('select[aria-label="Language"]').setValue('lo');
        expect(wrapper.text()).toContain('ດາວໂຫຼດໄຟລ໌ແນບ');
        expect(wrapper.text()).toContain('ການອະນຸຍາດຖືກຖອນ');
        expect(wrapper.text()).not.toContain('Download attachments');
    });
});
