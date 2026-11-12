import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import DocumentMasterDataManagement from '../../resources/js/components/DocumentMasterDataManagement.vue';
import AdministrationPage from '../../resources/js/pages/AdministrationPage.vue';
import LanguageSwitcher from '../../resources/js/components/LanguageSwitcher.vue';
import { createLocalization } from '../../resources/js/localization/index.js';
import { settle, swalCancel, swalConfirm, swalText } from './swal.js';

const endpoints = { categories: '/md/categories', types: '/md/types', registers: '/md/registers', classifications: '/md/classifications' };
const scope = { scope_key: 'site:7', scope_type: 'site', company_id: 4, site_id: 7, permissions: ['master-data.view', 'master-data.manage'] };
const category = { id: 1, code: 'GENERAL', name_en: 'General', name_lo: 'ທົ່ວໄປ', description_en: '', description_lo: '', active: true, effective: true };
const register = { id: 11, register_key: 'incoming', active: true, effective: true };
const type = { ...category, id: 3, code: 'LETTER', name_en: 'Letter', name_lo: 'ໜັງສື', category_id: 1, category, register_ids: [11], effective_registers: ['incoming'] };
const descriptors = Object.getOwnPropertyDescriptors(HTMLDialogElement.prototype);
enableAutoUnmount(afterEach);
beforeAll(() => Object.defineProperties(HTMLDialogElement.prototype, {
    showModal: { configurable: true, value() { this.setAttribute('open', ''); } },
    close: { configurable: true, value() { if (this.open) { this.removeAttribute('open'); this.dispatchEvent(new Event('close')); } } },
}));
afterAll(() => {
    for (const name of ['showModal', 'close']) {
        if (descriptors[name]) { Object.defineProperty(HTMLDialogElement.prototype, name, descriptors[name]); }
        else { delete HTMLDialogElement.prototype[name]; }
    }
});
beforeEach(() => localStorage.clear());
afterEach(() => { document.body.replaceChildren(); vi.unstubAllGlobals(); });
function response(data, status = 200) { return { ok: status < 400, status, json: async () => structuredClone(data) }; }
function server({ rows = {}, error = null } = {}) {
    const state = { categories: [category], types: [type], registers: [register], ...structuredClone(rows) };
    const fetch = vi.fn(async (url, options = {}) => {
        const parsed = new URL(url, 'http://localhost');
        const path = parsed.pathname;
        const method = options.method ?? 'GET';
        if (path === '/contexts') { return response({ data: [{ ...scope, company: { name_en: 'Example Company', name_lo: 'ບໍລິສັດຕົວຢ່າງ' }, site: { name_en: 'Example Site', name_lo: 'ສະຖານທີ່ຕົວຢ່າງ' } }] }); }
        if (method !== 'GET' && error) { return response(error.body, error.status); }
        if (path === endpoints.classifications) {
            return response({ data: ['normal', 'confidential', 'highly_confidential'].map(value => ({ value, label_key: `confidentiality-${value}`, description_key: `confidentiality-${value}-description` })) });
        }
        const kind = Object.keys(state).find(kind => path === endpoints[kind] || path.startsWith(`${endpoints[kind]}/`));
        if (!kind) { throw new Error(`Unexpected fixture request ${path}`); }
        if (method === 'GET') {
            const perPage = Number(parsed.searchParams.get('per_page') ?? 10);
            const page = Number(parsed.searchParams.get('page') ?? 1);
            return response({ data: state[kind].slice((page - 1) * perPage, page * perPage), meta: { total: state[kind].length, last_page: Math.max(1, Math.ceil(state[kind].length / perPage)) } });
        }
        const data = JSON.parse(options.body);
        if (method === 'POST') { state[kind].push({ id: 50, effective: data.active, ...data }); }
        else { Object.assign(state[kind].find(row => row.id === Number(path.split('/').at(-1))), data); }
        return response({ data }, method === 'POST' ? 201 : 200);
    });
    vi.stubGlobal('fetch', fetch);
    return fetch;
}
async function render(currentScope = scope) {
    const wrapper = mount({
        components: { DocumentMasterDataManagement, LanguageSwitcher },
        props: { scope: { type: Object, required: true } },
        setup: () => ({ endpoints }),
        template: '<div id="dcms-app"><LanguageSwitcher /><DocumentMasterDataManagement :scope="scope" :endpoints="endpoints" csrf-token="sample-csrf" /></div>',
    }, { props: { scope: structuredClone(currentScope) }, attachTo: document.body, global: { plugins: [createLocalization()] } });
    await flushPromises();
    return wrapper;
}
function button(wrapper, text) { const found = wrapper.findAll('button').find(item => item.text() === text); expect(found, text).toBeDefined(); return found; }
function control(wrapper, text) {
    const label = wrapper.findAll('label').find(item => item.text().replace('(required)', '').replace('*', '').trim() === text);
    expect(label, text).toBeDefined();
    return wrapper.get(`#${label.attributes('for')}`);
}
async function names(wrapper) {
    await control(wrapper, 'Stable code').setValue('NEW_RECORD');
    await control(wrapper, 'English display name').setValue('Example');
    await control(wrapper, 'Lao display name').setValue('ຕົວຢ່າງ');
}
function section(wrapper, kind) { return wrapper.get(`section[aria-labelledby="master-${kind}"]`); }

describe('document master-data administration', () => {
    it('integrates without security-administration permissions and translates lists and fixed classifications', async () => {
        server();
        const wrapper = mount(AdministrationPage, { props: { endpoints: { contexts: '/contexts', masterData: endpoints }, csrfToken: 'sample-csrf' }, global: { plugins: [createLocalization()] } });
        await flushPromises();
        expect(wrapper.text()).toContain('Document Master Data');
        expect(wrapper.text()).toContain('Highly Confidential');
        expect(wrapper.text()).not.toContain('Create role');
        expect(wrapper.text()).not.toContain('Assign membership');
    });

    it('switches English and Lao names, register labels, reference descriptions and controls', async () => {
        server();
        const wrapper = await render();
        expect(wrapper.text()).toContain('Incoming Correspondence');
        await wrapper.get('[data-language-switcher] input[value="lo"]').setValue(true);
        expect(wrapper.text()).toContain('ຂໍ້ມູນຫຼັກເອກະສານ');
        expect(wrapper.text()).toContain('ທົ່ວໄປ');
        expect(wrapper.text()).toContain('ໜັງສືເຂົ້າ');
        expect(wrapper.text()).toContain('ລັບສູງ');
        expect(wrapper.text()).not.toContain('master-');
    });

    it('creates a bilingual category with the exact scope and CSRF, then reports success', async () => {
        const fetch = server({ rows: { categories: [] } });
        const wrapper = await render();
        expect(section(wrapper, 'categories').text()).toContain('No records to display.');
        await button(wrapper, 'Create · Document categories').trigger('click');
        await names(wrapper);
        await control(wrapper, 'Lao description (optional)').setValue('ຄຳອະທິບາຍ');
        await wrapper.get('form').trigger('submit');
        await flushPromises();
        const [url, options] = fetch.mock.calls.find(([, options]) => options.method === 'POST');
        expect(url).toBe(endpoints.categories);
        expect(options.headers['X-CSRF-TOKEN']).toBe('sample-csrf');
        expect(options.credentials).toBe('same-origin');
        expect(JSON.parse(options.body)).toMatchObject({ scope_type: 'site', company_id: 4, site_id: 7, code: 'NEW_RECORD', name_en: 'Example', name_lo: 'ຕົວຢ່າງ', description_lo: 'ຄຳອະທິບາຍ', active: true });
        await settle();
        expect(swalText()).toContain('Document master data saved.');
    });

    it('creates a type with an explicit category and applicable registers and protects category ownership during edits', async () => {
        const fetch = server();
        const wrapper = await render();
        await button(wrapper, 'Create · Document types').trigger('click');
        await flushPromises();
        expect(section(wrapper, 'types').get('tbody').text()).toContain('Incoming Correspondence');
        await names(wrapper);
        await control(wrapper, 'Category').setValue(1);
        await wrapper.get('fieldset input[type="checkbox"]').setValue(true);
        await wrapper.get('form').trigger('submit');
        await flushPromises();
        const [, options] = fetch.mock.calls.find(([url, options]) => url === endpoints.types && options.method === 'POST');
        expect(JSON.parse(options.body)).toMatchObject({ category_id: 1, register_ids: [11] });
        await section(wrapper, 'types').findAll('button').find(item => item.text() === 'Edit').trigger('click');
        await flushPromises();
        expect(control(wrapper, 'Category').element.disabled).toBe(true);
        expect(control(wrapper, 'Stable code').element.readOnly).toBe(true);
        await control(wrapper, 'English display name').setValue('Edited type');
        await wrapper.get('form').trigger('submit');
        await flushPromises();
        const [, patch] = fetch.mock.calls.find(([, options]) => options.method === 'PATCH');
        expect(JSON.parse(patch.body)).toMatchObject({ name_en: 'Edited type', register_ids: [11] });
        expect(JSON.parse(patch.body)).not.toHaveProperty('category_id');
        expect(JSON.parse(patch.body)).not.toHaveProperty('code');
    });

    it('offers only the three fixed register identities and prevents duplicate identity selection', async () => {
        const fetch = server();
        const wrapper = await render();
        await button(wrapper, 'Create · Register configuration').trigger('click');
        const select = control(wrapper, 'Register');
        expect(select.findAll('option').map(option => option.element.value)).toEqual(['incoming', 'outgoing', 'controlled']);
        expect(select.findAll('option')[0].element.disabled).toBe(true);
        await select.setValue('outgoing');
        await wrapper.get('form').trigger('submit');
        await flushPromises();
        const [, options] = fetch.mock.calls.find(([url, options]) => url === endpoints.registers && options.method === 'POST');
        expect(JSON.parse(options.body)).toMatchObject({ register_key: 'outgoing', scope_type: 'site' });
    });

    it.each([true, false])('requires an explicit confirmation before changing active=%s', async (active) => {
        const fetch = server({ rows: { categories: [{ ...category, active }] } });
        const wrapper = await render();
        await button(section(wrapper, 'categories'), active ? 'Deactivate' : 'Reactivate').trigger('click');
        await flushPromises();
        await settle();
        expect(swalText()).toContain('General');
        expect(fetch.mock.calls.some(([, options]) => options.method === 'PATCH')).toBe(false);
        await swalCancel();
        expect(fetch.mock.calls.some(([, options]) => options.method === 'PATCH')).toBe(false);
        await button(section(wrapper, 'categories'), active ? 'Deactivate' : 'Reactivate').trigger('click');
        await flushPromises();
        await swalConfirm();
        await flushPromises();
        const [, options] = fetch.mock.calls.find(([, options]) => options.method === 'PATCH');
        expect(JSON.parse(options.body)).toEqual({ scope_type: 'site', company_id: 4, site_id: 7, active: !active });
    });

    it('lets read-only users inspect details without offering mutations or rendering user text as HTML', async () => {
        const fetch = server({ rows: { categories: [{ ...category, name_en: '<img src=x onerror=alert(1)>' }] } });
        const wrapper = await render({ ...scope, permissions: ['master-data.view'] });
        expect(wrapper.text()).not.toContain('Create ·');
        expect(wrapper.text()).not.toContain('Deactivate');
        expect(wrapper.find('img').exists()).toBe(false);
        await button(section(wrapper, 'categories'), 'Details').trigger('click');
        await flushPromises();
        expect(control(wrapper, 'English display name').element.readOnly).toBe(true);
        expect(wrapper.text()).not.toContain('Save configuration');
        expect(fetch.mock.calls.every(([, options]) => !options.method || options.method === 'GET')).toBe(true);
    });

    it('paginates lists and retrieves all category option pages rather than silently excluding later categories', async () => {
        const rows = Array.from({ length: 51 }, (_, index) => ({ ...category, id: index + 1, name_en: `Category ${index + 1}` }));
        const fetch = server({ rows: { categories: rows } });
        const wrapper = await render();
        await button(section(wrapper, 'categories'), 'Next').trigger('click');
        await flushPromises();
        expect(section(wrapper, 'categories').text()).toContain('Category 11');
        await button(wrapper, 'Create · Document types').trigger('click');
        await flushPromises();
        expect(control(wrapper, 'Category').findAll('option')).toHaveLength(52);
        expect(fetch.mock.calls.some(([url]) => url.includes('page=2') && url.includes('per_page=50'))).toBe(true);
    });

    it('focuses field validation errors and keeps the error translated when language changes', async () => {
        server({ error: { status: 422, body: { errors: { name_lo: ['rbacValidation'] } } } });
        const wrapper = await render();
        await button(wrapper, 'Create · Document categories').trigger('click');
        await names(wrapper);
        await wrapper.get('form').trigger('submit');
        await flushPromises();
        const field = control(wrapper, 'Lao display name');
        expect(document.activeElement).toBe(field.element);
        expect(field.attributes('aria-invalid')).toBe('true');
        await wrapper.get('[data-language-switcher] input[value="lo"]').setValue(true);
        expect(wrapper.findAll('[role="alert"]').some(item => item.text().includes('ກະລຸນາກວດສອບ'))).toBe(true);
    });

    it('removes data and controls after server authority revocation', async () => {
        server({ error: { status: 403, body: { message: 'rbacForbidden' } } });
        const wrapper = await render();
        await button(section(wrapper, 'categories'), 'Deactivate').trigger('click');
        await flushPromises();
        await swalConfirm();
        await flushPromises();
        expect(wrapper.text()).toContain('You do not have permission');
        expect(wrapper.text()).not.toContain('Create ·');
        expect(wrapper.text()).not.toContain('General');
        expect(wrapper.findComponent(DocumentMasterDataManagement).emitted('unauthorized')).toHaveLength(1);
    });

    it('clears the editor and ignores late results when the organization scope changes', async () => {
        const fetch = server();
        const original = fetch.getMockImplementation();
        let finish;
        const delayed = new Promise(resolve => { finish = resolve; });
        fetch.mockImplementation(async (url, options) => {
            if (url.startsWith(endpoints.types) && url.includes('site_id=7')) { return delayed; }
            return original(url, options);
        });
        const wrapper = await render();
        await wrapper.setProps({ scope: { ...scope, scope_key: 'site:8', site_id: 8 } });
        await flushPromises();
        finish(response({ data: [{ ...type, name_en: 'Stale site result' }], meta: { total: 1, last_page: 1 } }));
        await flushPromises();
        expect(wrapper.text()).not.toContain('Stale site result');
        expect(fetch.mock.calls.some(([url]) => url.includes('site_id=8'))).toBe(true);
    });
});

it('keeps review policies disabled initially and submits only explicitly configured type settings', async () => {
    const fetch = server(); const wrapper = await render();
    await button(section(wrapper, 'types'), 'Edit').trigger('click'); await flushPromises();
    expect(wrapper.get('#master-review-enabled').element.checked).toBe(false);
    expect(wrapper.get('#master-review-months').element.value).toBe('');
    expect(wrapper.get('#master-review-baseline').element.value).toBe('');
    await wrapper.get('#master-review-enabled').setValue(true);
    await wrapper.get('#master-review-months').setValue('36');
    await wrapper.get('#master-review-baseline').setValue('completion_date');
    await wrapper.get('form').trigger('submit'); await flushPromises();
    const [, options] = fetch.mock.calls.find(([url, options]) => options.method === 'PATCH' && url.includes('/types/3'));
    expect(JSON.parse(options.body)).toMatchObject({ scope_type: 'site', company_id: 4, site_id: 7, review_enabled: true, review_interval_months: 36, review_baseline: 'completion_date' });
    expect(options.headers['X-CSRF-TOKEN']).toBe('sample-csrf');
});
