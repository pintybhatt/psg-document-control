import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import NumberingManagement from '../../resources/js/components/NumberingManagement.vue';
import AdministrationPage from '../../resources/js/pages/AdministrationPage.vue';
import LanguageSwitcher from '../../resources/js/components/LanguageSwitcher.vue';
import { createLocalization } from '../../resources/js/localization/index.js';

const endpoint = '/numbering-series';
const scope = { scope_key: 'site:7', scope_type: 'site', company_id: 4, site_id: 7, permissions: ['numbering.view', 'numbering.manage'] };
const registers = [{ id: 11, register_key: 'outgoing', active: true }, { id: 12, register_key: 'incoming', active: true }];
const configuration = { id: 5, version: 1, prefix: 'EXAMPLE/OUT', padding: 6, active: true, effective_at: '2026-10-26T00:00:00Z', created_by: 2, change_reason: 'Initial configuration' };
const series = { id: 1, register_id: 11, register_key: 'outgoing', purpose: 'internal_register', reset_policy: 'annual', effective: true, configuration };
const descriptors = Object.getOwnPropertyDescriptors(HTMLDialogElement.prototype);
enableAutoUnmount(afterEach);
beforeEach(() => localStorage.clear());
afterEach(() => { document.body.replaceChildren(); vi.unstubAllGlobals(); });
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
function response(data, status = 200) { return { ok: status < 400, status, json: async () => structuredClone(data) }; }
function server({ rows = [series], error = null, registerRows = registers, history = [configuration] } = {}) {
    const state = structuredClone(rows);
    const fetch = vi.fn(async (url, options = {}) => {
        const path = new URL(url, 'http://localhost').pathname;
        const method = options.method ?? 'GET';
        if (path === '/contexts') { return response({ data: [{ ...scope, company: { name_en: 'Example Company', name_lo: 'ບໍລິສັດຕົວຢ່າງ' }, site: { name_en: 'Example Site', name_lo: 'ສະຖານທີ່ຕົວຢ່າງ' } }] }); }
        if (method === 'GET' && path === endpoint) { return response({ data: state, meta: { total: state.length } }); }
        if (method === 'GET' && path.endsWith('/registers')) { return response({ data: registerRows }); }
        if (method === 'GET' && path.endsWith('/history')) { return response({ data: history, total: history.length }); }
        if (path.endsWith('/preview')) {
            const body = JSON.parse(options.body);
            return response({ number: `${body.prefix}/${body.year}/${'1'.padStart(body.padding, '0')}` });
        }
        if (error) { return response(error.body, error.status); }
        const body = JSON.parse(options.body);
        if (method === 'POST') { state.push({ ...series, id: 2, ...body, configuration: { ...configuration, ...body } }); }
        if (method === 'PATCH') { state[0].configuration = { ...configuration, ...body, version: 2 }; }
        return response({ data: state[0] }, method === 'POST' ? 201 : 200);
    });
    vi.stubGlobal('fetch', fetch);
    return fetch;
}
async function render(currentScope = scope) {
    const wrapper = mount({
        components: { NumberingManagement, LanguageSwitcher },
        props: { scope: Object },
        setup: () => ({ endpoint }),
        template: '<div id="dcms-app"><LanguageSwitcher /><NumberingManagement :scope="scope" :endpoint="endpoint" csrf-token="sample-csrf" /></div>',
    }, { props: { scope: structuredClone(currentScope) }, attachTo: document.body, global: { plugins: [createLocalization()] } });
    await flushPromises();
    return wrapper;
}
function button(wrapper, text) { const result = wrapper.findAll('button').find(item => item.text() === text); expect(result, text).toBeDefined(); return result; }
function control(wrapper, text) {
    const label = wrapper.findAll('label').find(item => item.text().replace('(required)', '').replace('*', '').trim() === text);
    expect(label, text).toBeDefined();
    return wrapper.get(`#${label.attributes('for')}`);
}
async function confirmSave(wrapper) {
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    await button(wrapper.get('dialog'), 'Confirm').trigger('click');
    await flushPromises();
}

describe('numbering administration', () => {
    it('integrates independently of roles and master-data administration and translates numbering terminology', async () => {
        server();
        const wrapper = mount(AdministrationPage, { props: { endpoints: { contexts: '/contexts', numbering: endpoint }, csrfToken: 'sample-csrf' }, global: { plugins: [createLocalization()] } });
        await flushPromises();
        expect(wrapper.text()).toContain('Document numbering');
        expect(wrapper.text()).not.toContain('Create role');
        expect(wrapper.text()).not.toContain('Document Master Data');
        wrapper.unmount();
        const bilingual = await render();
        await bilingual.get('select[aria-label="Language"]').setValue('lo');
        expect(bilingual.text()).toContain('ການອອກເລກເອກະສານ');
        expect(bilingual.text()).toContain('ເລກທະບຽນພາຍໃນ');
        expect(bilingual.text()).toContain('ເລີ່ມເລກໃໝ່ປະຈຳປີ');
    });

    it('previews a format with an explicit year without creating a series or sending a sequence', async () => {
        const fetch = server({ rows: [] });
        const wrapper = await render();
        expect(wrapper.text()).toContain('No records to display.');
        await button(wrapper, 'Create numbering series').trigger('click');
        await control(wrapper, 'Prefix').setValue('EXAMPLE/DOC');
        await control(wrapper, 'Minimum number of digits (1–12)').setValue('4');
        await control(wrapper, 'Preview year').setValue('2027');
        await button(wrapper, 'Preview format without allocation').trigger('click');
        await flushPromises();
        expect(wrapper.text()).toContain('EXAMPLE/DOC/2027/0001');
        const calls = fetch.mock.calls.filter(([, options]) => options.method === 'POST');
        expect(calls).toHaveLength(1);
        expect(calls[0][0]).toBe(`${endpoint}/preview`);
        expect(JSON.parse(calls[0][1].body)).toEqual({ scope_type: 'site', company_id: 4, site_id: 7, prefix: 'EXAMPLE/DOC', padding: 4, year: 2027 });
        await control(wrapper, 'Prefix').setValue('CHANGED');
        expect(wrapper.text()).not.toContain('EXAMPLE/DOC/2027/0001');
    });

    it('creates a continuous official series only after confirmation with scope and CSRF', async () => {
        const fetch = server({ rows: [] });
        const wrapper = await render();
        await button(wrapper, 'Create numbering series').trigger('click');
        await control(wrapper, 'Register').setValue(11);
        await control(wrapper, 'Numbering purpose').setValue('official_outgoing');
        await control(wrapper, 'Reset policy').setValue('continuous');
        await control(wrapper, 'Prefix').setValue('EXAMPLE/OFFICIAL');
        await wrapper.get('form').trigger('submit');
        await flushPromises();
        expect(wrapper.get('dialog').element.open).toBe(true);
        expect(fetch.mock.calls.some(([url, options]) => url === endpoint && options.method === 'POST')).toBe(false);
        await button(wrapper.get('dialog'), 'Confirm').trigger('click');
        await flushPromises();
        const [, options] = fetch.mock.calls.find(([url, options]) => url === endpoint && options.method === 'POST');
        expect(options.headers['X-CSRF-TOKEN']).toBe('sample-csrf');
        expect(options.credentials).toBe('same-origin');
        expect(JSON.parse(options.body)).toMatchObject({ register_id: 11, purpose: 'official_outgoing', reset_policy: 'continuous', prefix: 'EXAMPLE/OFFICIAL', company_id: 4, site_id: 7 });
        expect(wrapper.text()).toContain('No number was allocated.');
    });

    it('locks permanent identity fields while allowing audited formatting and inactive-state edits', async () => {
        const fetch = server();
        const wrapper = await render();
        await button(wrapper, 'Edit').trigger('click');
        for (const name of ['Register', 'Numbering purpose', 'Reset policy']) { expect(control(wrapper, name).element.disabled).toBe(true); }
        await control(wrapper, 'Prefix').setValue('EXAMPLE/NEW');
        await control(wrapper, 'Active').setValue(false);
        await control(wrapper, 'Reason for change (optional)').setValue('Deactivate configuration');
        await confirmSave(wrapper);
        const [, options] = fetch.mock.calls.find(([, options]) => options.method === 'PATCH');
        expect(JSON.parse(options.body)).toMatchObject({ prefix: 'EXAMPLE/NEW', active: false, change_reason: 'Deactivate configuration' });
        expect(JSON.parse(options.body)).not.toHaveProperty('reset_policy');
        expect(JSON.parse(options.body)).not.toHaveProperty('counter');
        expect(wrapper.text()).toContain('Inactive');
    });

    it('keeps history read-only and safely renders untrusted change reasons', async () => {
        server({ history: [{ ...configuration, change_reason: '<img src=x onerror=alert(1)>' }] });
        const wrapper = await render({ ...scope, permissions: ['numbering.view'] });
        expect(wrapper.text()).not.toContain('Create numbering series');
        await button(wrapper, 'Configuration history').trigger('click');
        await flushPromises();
        expect(wrapper.get('section[aria-labelledby="numbering-history"]').text()).toContain('<img src=x onerror=alert(1)>');
        expect(wrapper.find('img').exists()).toBe(false);
        await button(wrapper, 'Details').trigger('click');
        expect(control(wrapper, 'Prefix').element.readOnly).toBe(true);
        expect(wrapper.find('button[type="submit"]').exists()).toBe(false);
    });

    it('marks inactive-register configurations unavailable and disables official numbering for incoming registers', async () => {
        server({ rows: [{ ...series, effective: false }], registerRows: registers.map(row => ({ ...row, active: row.id !== 11 })) });
        const wrapper = await render();
        expect(wrapper.text()).toContain('Active, unavailable');
        await button(wrapper, 'Create numbering series').trigger('click');
        await control(wrapper, 'Register').setValue(12);
        const purpose = control(wrapper, 'Numbering purpose');
        expect(purpose.find('option[value="official_outgoing"]').element.disabled).toBe(true);
        expect(control(wrapper, 'Register').find('option[value="11"]').element.disabled).toBe(true);
    });

    it('shows a configuration conflict in English/Lao and hides stale controls when authority is revoked', async () => {
        server({ error: { status: 422, body: { errors: { prefix: ['numberingConflict'] } } } });
        const wrapper = await render();
        await button(wrapper, 'Edit').trigger('click');
        await confirmSave(wrapper);
        expect(wrapper.findAll('[role="alert"]').some(item => item.text().includes('already reserved'))).toBe(true);
        await wrapper.get('select[aria-label="Language"]').setValue('lo');
        expect(wrapper.findAll('[role="alert"]').some(item => item.text().includes('ຖືກສະຫງວນ'))).toBe(true);
        wrapper.unmount(); localStorage.clear();
        server({ error: { status: 403, body: { message: 'rbacForbidden' } } });
        const revoked = await render();
        await button(revoked, 'Edit').trigger('click');
        await confirmSave(revoked);
        expect(revoked.text()).toContain('You do not have permission');
        expect(revoked.text()).not.toContain('EXAMPLE/OUT');
        expect(revoked.text()).not.toContain('Create numbering series');
    });

    it('clears preview and history and ignores late preview responses after a scope change', async () => {
        const fetch = server();
        const original = fetch.getMockImplementation();
        let finish;
        const delayed = new Promise(resolve => { finish = resolve; });
        fetch.mockImplementation(async (url, options) => url.endsWith('/preview') ? delayed : original(url, options));
        const wrapper = await render();
        await button(wrapper, 'Edit').trigger('click');
        await button(wrapper, 'Preview format without allocation').trigger('click');
        await wrapper.setProps({ scope: { ...scope, scope_key: 'site:8', site_id: 8 } });
        finish(response({ number: 'STALE-PREVIEW' }));
        await flushPromises();
        expect(wrapper.find('form').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('STALE-PREVIEW');
        expect(fetch.mock.calls.some(([url]) => url.includes('site_id=8'))).toBe(true);
    });
});
