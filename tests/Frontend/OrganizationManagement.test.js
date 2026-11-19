import { afterAll, afterEach, beforeAll, describe, expect, it, vi } from 'vitest';
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import OrganizationManagement from '../../resources/js/components/OrganizationManagement.vue';
import LanguageSwitcher from '../../resources/js/components/LanguageSwitcher.vue';
import { createLocalization } from '../../resources/js/localization/index.js';

const company = { id: 1, code: 'SYNTHETIC', name_en: 'Synthetic Company', name_lo: 'ບໍລິສັດຕົວຢ່າງ', active: true };
const site = { ...company, id: 2, company_id: 1, code: 'SITE' };
const dialogDescriptors = Object.getOwnPropertyDescriptors(HTMLDialogElement.prototype);
beforeAll(() => {
    Object.defineProperties(HTMLDialogElement.prototype, {
        showModal: { configurable: true, value() { this.setAttribute('open', ''); } },
        close: { configurable: true, value() { this.removeAttribute('open'); } },
    });
});
afterAll(() => {
    for (const name of ['showModal', 'close']) {
        if (dialogDescriptors[name]) { Object.defineProperty(HTMLDialogElement.prototype, name, dialogDescriptors[name]); }
        else { delete HTMLDialogElement.prototype[name]; }
    }
});
enableAutoUnmount(afterEach);
afterEach(() => { vi.unstubAllGlobals(); localStorage.clear(); document.body.replaceChildren(); });
function reply(data, status = 200) { return { ok: status < 400, status, json: async () => structuredClone(data) }; }
function button(wrapper, text) { return wrapper.findAll('button').find(item => item.text() === text); }
function server(error = null) {
    const fetch = vi.fn(async (url, options = {}) => {
        if (options.method && options.method !== 'GET') { return error ? reply({ errors: { active: ['orgInUse'] } }, error) : reply({ data: company }, 201); }
        const data = url.includes('/departments') ? [] : url.includes('/sites') ? [site] : [company];
        return reply({ data, meta: { total: data.length } });
    });
    vi.stubGlobal('fetch', fetch); return fetch;
}
function render() {
    return mount({ components: { OrganizationManagement, LanguageSwitcher }, template: '<div><LanguageSwitcher /><OrganizationManagement endpoint="/api/v1/organization/companies" csrf-token="test-token" /></div>' }, { attachTo: document.body, global: { plugins: [createLocalization()] } });
}
describe('Organization structure maintenance', () => {
    it('navigates within selected parents and submits explicit bilingual structure without default activation', async () => {
        const fetch = server(); const wrapper = render(); await flushPromises();
        await button(wrapper, 'Sites').trigger('click'); await flushPromises();
        expect(fetch.mock.calls.at(-1)[0]).toContain('company_id=1');
        await button(wrapper, 'Departments').trigger('click'); await flushPromises();
        expect(fetch.mock.calls.at(-1)[0]).toContain('site_id=2');
        await button(wrapper, 'Add department').trigger('click'); await flushPromises();
        expect(document.activeElement).toBe(wrapper.get('input[name="org-code"]').element);
        expect(wrapper.get('input[type="checkbox"]').element.checked).toBe(false);
        await wrapper.get('[role="dialog"] form').trigger('submit'); await flushPromises();
        expect(wrapper.get('[aria-invalid="true"]').attributes('name')).toBe('org-code');
        expect(fetch.mock.calls.some(([, options]) => options.method && options.method !== 'GET')).toBe(false);
        await wrapper.get('input[name="org-code"]').setValue('NEW');
        await wrapper.get('input[name="org-name-en"]').setValue('New department');
        await wrapper.get('input[name="org-name-lo"]').setValue('ພະແນກໃໝ່');
        await wrapper.get('[role="dialog"] form').trigger('submit'); await flushPromises();
        const [url, options] = fetch.mock.calls.find(([, options]) => options.method === 'POST');
        expect(url).toBe('/api/v1/organization/departments');
        expect(JSON.parse(options.body)).toMatchObject({ code: 'NEW', company_id: 1, site_id: 2, active: false });
        expect(options.headers['X-CSRF-TOKEN']).toBe('test-token');
    });

    it('keeps stable identity read-only while editing and displays Lao labels', async () => {
        const fetch = server(); const wrapper = render(); await flushPromises();
        await button(wrapper, 'Edit').trigger('click'); await flushPromises();
        expect(wrapper.get('input[name="org-code"]').element.readOnly).toBe(true);
        await wrapper.get('input[name="org-name-en"]').setValue('Renamed company');
        await wrapper.get('[role="dialog"] form').trigger('submit'); await flushPromises();
        const [, options] = fetch.mock.calls.find(([, options]) => options.method === 'PATCH');
        expect(JSON.parse(options.body)).toEqual({ name_en: 'Renamed company', name_lo: company.name_lo, active: true });
        await wrapper.get('[data-language-switcher] input[value="lo"]').setValue(true);
        expect(wrapper.get('#org-heading').text()).toBe('ບໍລິສັດ');
        expect(wrapper.text()).not.toContain('org-');
    });

    it('searches across the selected organization’s pages by Lao name and clears the filter on navigation', async () => {
        const matchingSite = { ...site, id: 3, name_lo: 'ໂຄງການພູເຂົາ', code: 'SECOND' };
        const fetch = vi.fn(async url => {
            const request = new URL(url, 'http://localhost');
            if (request.pathname.endsWith('/companies')) { return reply({ data: [company], meta: { total: 1, last_page: 1 } }); }
            const isSecondPage = request.searchParams.get('page') === '2';
            return reply({ data: [isSecondPage ? matchingSite : site], meta: { total: 2, last_page: 2 } });
        });
        vi.stubGlobal('fetch', fetch);
        const wrapper = render(); await flushPromises();
        await button(wrapper, 'Sites').trigger('click'); await flushPromises();
        expect(wrapper.get('#org-heading').text()).toBe('Sites');
        await wrapper.get('input[type="search"]').setValue('ພູເຂົາ');
        await wrapper.get('form').trigger('submit'); await flushPromises();
        expect(wrapper.findAll('tbody tr')).toHaveLength(1);
        expect(wrapper.get('tbody').text()).toContain('SECOND');
        expect(fetch.mock.calls.at(-1)[0]).toContain('page=2&per_page=50&company_id=1');
        await button(wrapper, 'Companies').trigger('click'); await flushPromises();
        expect(wrapper.get('input[type="search"]').element.value).toBe('');
        expect(wrapper.get('tbody').text()).toContain('SYNTHETIC');
    });

    it('clears records and emits unauthorized when server authority is revoked', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => reply({ message: 'rbacForbidden' }, 403)));
        const wrapper = mount(OrganizationManagement, { props: { endpoint: '/organization/companies', csrfToken: 'test' }, global: { plugins: [createLocalization()] } });
        await flushPromises();
        expect(wrapper.emitted('unauthorized')).toHaveLength(1);
        expect(wrapper.text()).not.toContain('Synthetic Company');
    });
});
