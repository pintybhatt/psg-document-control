import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import en from '../../resources/js/localization/en.js';
import lo from '../../resources/js/localization/lo.js';
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
function server({ rows = [series], error = null, registerRows = registers, history = [configuration], ambiguous = false, withoutSeries = false, letterTypes = [{ id: 32, scope_id: 9, code: 'LTR', name_en: 'Letter', name_lo: 'ຈົດໝາຍ' }] } = {}) {
    const state = structuredClone(rows);
    let policy = null;
    const fetch = vi.fn(async (url, options = {}) => {
        const path = new URL(url, 'http://localhost').pathname;
        const method = options.method ?? 'GET';
        if (path === '/contexts') { return response({ data: [{ ...scope, company: { name_en: 'Example Company', name_lo: 'ບໍລິສັດຕົວຢ່າງ' }, site: { name_en: 'Example Site', name_lo: 'ສະຖານທີ່ຕົວຢ່າງ' } }] }); }
        if (path.endsWith('/xppl/activate')) { policy = { activated_by: 2 }; return response({ policy_available: true, policy, departments: [], types: [], series: [], history: [], mappings: [] }); }
        if (path.endsWith('/xppl/options')) { return response({ policy_available: true, policy, departments: [{ id: 21, site_id: 7, code: 'ADM', name_en: 'Administration', name_lo: 'ບໍລິຫານ' }, { id: 22, site_id: 8, code: 'ENG', name_en: 'Engineering', name_lo: 'ວິສະວະກຳ' }], types: [{ id: 31, site_id: 7, scope_id: 8, code: 'SOP', name_en: 'Procedure', name_lo: 'ລະບຽບ' }, ...letterTypes], series: withoutSeries ? [] : [{ id: 1, site_id: 7, scope_id: 8, register: 'controlled', purpose: 'internal_register' }, { id: 2, site_id: 8, scope_id: 9, register: 'outgoing', purpose: 'official_outgoing' }], history: [] }); }
        if (/\/xppl\/(review|confirm)$/.test(path)) { if (JSON.parse(options.body).format === 'xppl_letter') { return response({ preview: 'XPPL-ENG-LTR-26-0043', format: 'xppl_letter', next_sequence: 43, historical_maximum: 42, ambiguous, collision_count: 0, token: 'letter-token' }); } return response({ prefix: 'XPPL-ADM-SOP', format: 'xppl_controlled', preview: 'XPPL-ADM-SOP-0043', historical_maximum: 42, next_sequence: 43, ambiguous, collision_count: 0, token: 'review-token' }); }
        if (method === 'GET' && path === endpoint) { return response({ data: state, meta: { total: state.length } }); }
        if (method === 'GET' && path.endsWith('/registers')) { return response({ data: registerRows }); }
        if (method === 'GET' && path.endsWith('/history')) { return response({ data: history, total: history.length }); }
        if (path.endsWith('/preview')) {
            const body = JSON.parse(options.body);
            if (body.series_id) { return response({ number: 'EXAMPLE/OUT/2026/000008' }); }
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
async function expand(wrapper, selector) { wrapper.get(selector).element.open = true; await wrapper.get(selector).trigger('toggle'); }
async function confirmSave(wrapper) {
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    await button(wrapper.get('dialog'), 'Confirm').trigger('click');
    await flushPromises();
}

describe('numbering administration', () => {
    it('activates company-wide numbering once only after confirmation with bilingual status', async () => {
        const fetch = server({ withoutSeries: true });
        const wrapper = await render({ ...scope, scope_type: 'company', site_id: null, scope_key: 'company:4' });
        const policyPanel = wrapper.get('section[aria-labelledby="xppl-policy-title"]');
        expect(policyPanel.text()).toContain(en.rbacInactive);
        const activate = button(wrapper, en.numberingPolicyActivate);
        expect(activate.element.disabled).toBe(true);
        expect(fetch.mock.calls.some(([url]) => url.endsWith('/xppl/activate'))).toBe(false);
        await control(wrapper, en.numberingPolicyConfirm).setValue(true);
        await activate.trigger('click'); await flushPromises();
        const calls = fetch.mock.calls.filter(([url]) => url.endsWith('/xppl/activate'));
        expect(calls).toHaveLength(1);
        expect(JSON.parse(calls[0][1].body)).toEqual({ scope_type: 'company', company_id: 4, site_id: null, confirm: true });
        expect(policyPanel.text()).toContain(en.rbacActive);
        expect(wrapper.findAll('button').some(item => item.text() === en.numberingPolicyActivate)).toBe(false);
        await wrapper.get('select[aria-label="Language"]').setValue('lo');
        expect(policyPanel.text()).toContain(lo.numberingPolicyStatus);
        expect(policyPanel.text()).toContain(lo.rbacActive);
    });
    it('shows simple bilingual guidance with advanced and legacy controls collapsed and no mutations', async () => {
        const fetch = server();
        const wrapper = await render({ ...scope, scope_type: 'company', site_id: null, scope_key: 'company:4' });
        expect(wrapper.text()).toContain('COMP-DEPT-TYPE-0001-R00');
        expect(wrapper.text()).toContain('COMP-DEPT-LTR-YY-0001');
        expect(wrapper.text()).toContain('Document numbers are generated automatically');
        expect(wrapper.get('[data-numbering-advanced]').element.open).toBe(false);
        expect(wrapper.get('[data-numbering-legacy]').element.open).toBe(false);
        expect(wrapper.get('[data-numbering-legacy]').findAll('button').some(item => item.text() === en.numberingCreate)).toBe(true);
        expect(wrapper.findAll('label').some(item => item.text().includes(en.numberingXpplSeries))).toBe(false);
        await expand(wrapper, '[data-numbering-advanced]');
        expect(wrapper.findAll('label').some(item => item.text().includes(en.numberingSetupType))).toBe(true);
        await wrapper.get('select[aria-label="Language"]').setValue('lo');
        expect(wrapper.text()).toContain(lo.numberingAutomaticHelp);
        expect(wrapper.get('[data-numbering-advanced] > summary').text()).toBe(lo.numberingAdvancedDetails);
        expect(wrapper.get('[data-numbering-legacy] > summary').text()).toBe(lo.numberingLegacySettings);
        expect(fetch.mock.calls.every(([, options]) => !options.method || options.method === 'GET')).toBe(true);
    });

    it('shows only letter settings and sends the existing LTR type without changing any configuration', async () => {
        const fetch = server();
        const wrapper = await render({ ...scope, scope_type: 'company', site_id: null, scope_key: 'company:4' });
        await expand(wrapper, '[data-numbering-advanced]');
        await control(wrapper, en.numberingSetupType).setValue('xppl_letter');
        const series = control(wrapper, en.numberingXpplSeries);
        expect(series.find('option[value="1"]').exists()).toBe(false);
        await series.setValue(2);
        expect(wrapper.findAll('label').some(item => item.text() === en.numberingXpplType)).toBe(false);
        await control(wrapper, en.numberingXpplDepartment).setValue(22);
        await button(wrapper, en.numberingXpplReview).trigger('click'); await flushPromises();
        expect(wrapper.text()).toContain('XPPL-ENG-LTR-26-0043');
        expect(wrapper.text()).not.toContain('XPPL-ENG-LTR-26-0043-R00');
        const [, options] = fetch.mock.calls.find(([url]) => url.endsWith('/xppl/review'));
        expect(JSON.parse(options.body)).toMatchObject({ series_id: 2, department_id: 22, type_id: 32, format: 'xppl_letter' });
        expect(fetch.mock.calls.some(([url]) => url.endsWith('/xppl/confirm'))).toBe(false);
        await control(wrapper, en.numberingSetupType).setValue('xppl_controlled');
        expect(wrapper.text()).not.toContain('XPPL-ENG-LTR-26-0043');
        expect(wrapper.findAll('label').some(item => item.text() === en.numberingXpplSeries)).toBe(false);
    });

    it.each([{ letterTypes: [] }, { letterTypes: [{ id: 32, scope_id: 9, code: 'LTR' }, { id: 33, scope_id: 9, code: 'LTR' }] }])('keeps letter review unavailable without a unique LTR master entry (%j)', async ({ letterTypes }) => {
        server({ letterTypes });
        const wrapper = await render({ ...scope, scope_type: 'company', site_id: null, scope_key: 'company:4' });
        await expand(wrapper, '[data-numbering-advanced]');
        await control(wrapper, en.numberingSetupType).setValue('xppl_letter');
        await control(wrapper, en.numberingXpplSeries).setValue(2);
        await control(wrapper, en.numberingXpplDepartment).setValue(22);
        expect(wrapper.text()).toContain(en.numberingLetterTypeMissing);
        expect(button(wrapper, en.numberingXpplReview).element.disabled).toBe(true);
    });

    it('keeps preview access read-only and closes setup when the company context changes', async () => {
        const fetch = server();
        const wrapper = await render({ ...scope, scope_type: 'company', site_id: null, scope_key: 'company:4', permissions: ['numbering.view'] });
        await expand(wrapper, '[data-numbering-advanced]');
        await control(wrapper, en.numberingSetupType).setValue('xppl_controlled');
        await control(wrapper, en.numberingXpplDepartment).setValue(21);
        await control(wrapper, en.numberingXpplType).setValue(31);
        await button(wrapper, en.numberingXpplReview).trigger('click'); await flushPromises();
        expect(wrapper.text()).toContain('XPPL-ADM-SOP-0043-R00');
        expect(wrapper.findAll('button').some(item => item.text() === en.numberingXpplConfirm)).toBe(false);
        expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(0);
        await wrapper.setProps({ scope: { ...scope, scope_type: 'company', company_id: 5, site_id: null, scope_key: 'company:5', permissions: ['numbering.view'] } });
        await flushPromises();
        expect(wrapper.get('[data-numbering-advanced]').element.open).toBe(false);
        expect(wrapper.text()).not.toContain('XPPL-ADM-SOP-0043-R00');
        expect(fetch.mock.calls.some(([url]) => url.endsWith('/xppl/confirm'))).toBe(false);
    });

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
        await expand(wrapper, '[data-numbering-legacy]');
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

    it('previews the saved series counter independently of unsaved format edits', async () => {
        const fetch = server();
        const wrapper = await render();
        await expand(wrapper, '[data-numbering-legacy]');
        await button(wrapper, 'Edit').trigger('click');
        await control(wrapper, 'Prefix').setValue('UNSAVED');
        await button(wrapper, 'Preview next configured number').trigger('click');
        await flushPromises();
        expect(wrapper.text()).toContain('EXAMPLE/OUT/2026/000008');
        const [, options] = fetch.mock.calls.find(([url]) => url.endsWith('/preview'));
        expect(JSON.parse(options.body)).toEqual({ scope_type: 'site', company_id: 4, site_id: 7, series_id: 1 });
        expect(wrapper.text()).toContain('does not reserve a number');
    });

    it('requires an explicit reviewed baseline confirmation without a legacy series and translates XPPL controls', async () => {
        const fetch = server({ withoutSeries: true });
        const wrapper = await render({ ...scope, scope_type: 'company', site_id: null, scope_key: 'company:4' });
        await expand(wrapper, '[data-numbering-advanced]');
        await control(wrapper, 'Numbering type').setValue('xppl_controlled');
        await control(wrapper, 'Originating department').setValue('21');
        await control(wrapper, 'Document type').setValue('31');
        await button(wrapper, 'Check existing numbers and preview').trigger('click');
        await flushPromises();
        expect(wrapper.text()).toContain('XPPL-ADM-SOP-0043-R00');
        const confirm = button(wrapper, 'Confirm numbering setup');
        expect(confirm.element.disabled).toBe(true);
        await control(wrapper, 'I have reviewed the existing numbers and approve this setup and starting value.').setValue(true);
        await confirm.trigger('click');
        await flushPromises();
        const [, options] = fetch.mock.calls.find(([url]) => url.endsWith('/xppl/confirm'));
        expect(JSON.parse(options.body)).not.toHaveProperty('series_id');
        expect(JSON.parse(options.body)).toMatchObject({ token: 'review-token', next_sequence: 43, confirm: true, format: 'xppl_controlled', department_id: 21, type_id: 31 });
        expect(confirm.element.disabled).toBe(true);
        await wrapper.get('select[aria-label="Language"]').setValue('lo');
        expect(wrapper.text()).toContain(lo.numberingAdvanced);
    });

    it('blocks ambiguous mappings and clears a reconciliation when its master selection changes', async () => {
        server({ ambiguous: true });
        const wrapper = await render({ ...scope, scope_type: 'company', site_id: null, scope_key: 'company:4' });
        await expand(wrapper, '[data-numbering-advanced]');
        await control(wrapper, 'Numbering type').setValue('xppl_controlled');
        await control(wrapper, 'Originating department').setValue('21');
        await control(wrapper, 'Document type').setValue('31');
        await button(wrapper, 'Check existing numbers and preview').trigger('click');
        await flushPromises();
        expect(button(wrapper, 'Confirm numbering setup').element.disabled).toBe(true);
        expect(control(wrapper, 'I have reviewed the existing numbers and approve this setup and starting value.').element.disabled).toBe(true);
        await control(wrapper, 'Document type').setValue('');
        expect(wrapper.text()).not.toContain('XPPL-ADM-SOP-0043-R00');
    });

    it('creates a continuous official series only after confirmation with scope and CSRF', async () => {
        const fetch = server({ rows: [] });
        const wrapper = await render();
        await expand(wrapper, '[data-numbering-legacy]');
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
        await expand(wrapper, '[data-numbering-legacy]');
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
        server({ history: [{ ...configuration, effective_at: '2026-10-26T18:35:00Z', change_reason: '<img src=x onerror=alert(1)>' }] });
        const wrapper = await render({ ...scope, permissions: ['numbering.view'] });
        await expand(wrapper, '[data-numbering-legacy]');
        expect(wrapper.text()).not.toContain('Create numbering series');
        await button(wrapper, 'Configuration history').trigger('click');
        await flushPromises();
        expect(wrapper.get('section[aria-labelledby="numbering-history"]').text()).toContain('<img src=x onerror=alert(1)>');
        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.get('section[aria-labelledby="numbering-history"]').text()).toContain('27/10/2026, 01:35');
        expect(wrapper.text()).not.toMatch(/\bUTC\b/);
        await button(wrapper, 'Details').trigger('click');
        expect(control(wrapper, 'Prefix').element.readOnly).toBe(true);
        expect(wrapper.find('button[type="submit"]').exists()).toBe(false);
        await wrapper.get('select[aria-label="Language"]').setValue('lo');
        expect(wrapper.text()).toContain('27/10/2026, 01:35');
        expect(wrapper.text()).not.toMatch(/\bUTC\b/);
    });

    it('marks inactive-register configurations unavailable and disables official numbering for incoming registers', async () => {
        server({ rows: [{ ...series, effective: false }], registerRows: registers.map(row => ({ ...row, active: row.id !== 11 })) });
        const wrapper = await render();
        await expand(wrapper, '[data-numbering-legacy]');
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
        await expand(wrapper, '[data-numbering-legacy]');
        await button(wrapper, 'Edit').trigger('click');
        await confirmSave(wrapper);
        expect(wrapper.findAll('[role="alert"]').some(item => item.text().includes('already reserved'))).toBe(true);
        await wrapper.get('select[aria-label="Language"]').setValue('lo');
        expect(wrapper.findAll('[role="alert"]').some(item => item.text().includes('ຖືກສະຫງວນ'))).toBe(true);
        wrapper.unmount(); localStorage.clear();
        server({ error: { status: 403, body: { message: 'rbacForbidden' } } });
        const revoked = await render();
        await expand(revoked, '[data-numbering-legacy]');
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
        await expand(wrapper, '[data-numbering-legacy]');
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
