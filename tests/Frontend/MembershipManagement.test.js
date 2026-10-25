import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import MembershipManagement from '../../resources/js/components/MembershipManagement.vue';
import AdministrationPage from '../../resources/js/pages/AdministrationPage.vue';
import LanguageSwitcher from '../../resources/js/components/LanguageSwitcher.vue';
import { createLocalization } from '../../resources/js/localization/index.js';

const endpoints = { contexts: '/contexts', memberships: '/memberships', membershipUsers: '/membership-users', membershipUnits: '/membership-units' };
const scope = { scope_key: 'site:7', scope_type: 'site', company_id: 4, site_id: 7, permissions: ['memberships.view', 'memberships.manage', 'users.view'] };
const user = { id: 9, name: 'Example Account', email: 'member@example.test' };
const company = { id: 4, name_en: 'Example Company', name_lo: 'ບໍລິສັດຕົວຢ່າງ' };
const site = { id: 7, name_en: 'Example Site', name_lo: 'ສະຖານທີ່ຕົວຢ່າງ' };
const department = { id: 8, name_en: 'Example Department', name_lo: 'ພະແນກຕົວຢ່າງ' };
const membership = { id: 20, user, company, site, department, membership_type: 'department', active: true, effective: true, status: 'effective', manageable: true };
const unit = { id: 7, unit_type: 'site', name_en: site.name_en, name_lo: site.name_lo, unit_company_id: 4, unit_site_id: 7, unit_department_id: null, company, site: null };
const descriptors = Object.getOwnPropertyDescriptors(HTMLDialogElement.prototype);
enableAutoUnmount(afterEach);
beforeAll(() => Object.defineProperties(HTMLDialogElement.prototype, {
    showModal: { configurable: true, value() { this.setAttribute('open', ''); } },
    close: { configurable: true, value() { if (this.open) { this.removeAttribute('open'); this.dispatchEvent(new Event('close')); } } },
}));
afterAll(() => {
    for (const key of ['showModal', 'close']) {
        if (descriptors[key]) { Object.defineProperty(HTMLDialogElement.prototype, key, descriptors[key]); }
        else { delete HTMLDialogElement.prototype[key]; }
    }
});
beforeEach(() => localStorage.clear());
afterEach(() => { document.body.replaceChildren(); vi.unstubAllGlobals(); });
function response(data, status = 200) { return { ok: status < 400, status, json: async () => structuredClone(data) }; }
function server({ rows = [membership], error = null, delay = null } = {}) {
    const fetch = vi.fn(async (url, options = {}) => {
        const path = new URL(url, 'http://localhost').pathname;
        if ((options.method ?? 'GET') !== 'GET') { return error ? response(error.data, error.status) : response(null, 204); }
        if (path === endpoints.contexts) { return response({ data: [{ ...scope, company, site }] }); }
        if (path === endpoints.memberships) { if (delay) { await delay; } return response({ data: rows, meta: { total: rows.length } }); }
        if (path === endpoints.membershipUsers) { return response({ data: [user] }); }
        if (path === endpoints.membershipUnits) { return response({ data: [unit] }); }
        throw new Error(`Unexpected request ${path}`);
    });
    vi.stubGlobal('fetch', fetch);
    return fetch;
}
async function render(currentScope = scope) {
    const wrapper = mount({
        components: { MembershipManagement, LanguageSwitcher },
        props: { scope: { type: Object, required: true } },
        setup: () => ({ endpoints }),
        template: '<div id="dcms-app"><LanguageSwitcher /><MembershipManagement :scope="scope" :endpoints="endpoints" csrf-token="sample-csrf" /></div>',
    }, { props: { scope: structuredClone(currentScope) }, attachTo: document.body, global: { plugins: [createLocalization()] } });
    await flushPromises();
    return wrapper;
}
function button(wrapper, text) { const found = wrapper.findAll('button').find(button => button.text() === text); expect(found).toBeDefined(); return found; }
function control(wrapper, text) {
    const label = wrapper.findAll('label').find(label => label.text().trim() === text);
    expect(label).toBeDefined();
    return wrapper.get(`#${label.attributes('for')}`);
}

describe('organization membership administration', () => {
    it('integrates with Administration independently of role-management permissions', async () => {
        server();
        const wrapper = mount(AdministrationPage, { props: { endpoints, csrfToken: 'sample-csrf' }, global: { plugins: [createLocalization()] } });
        await flushPromises();
        expect(wrapper.text()).toContain('User Organization Membership');
        expect(wrapper.text()).not.toContain('Create role');
        expect(wrapper.text()).toContain('Example Department');
    });

    it('renders bilingual units and effective, inactive and revoked statuses', async () => {
        server({ rows: [membership, { ...membership, id: 21, active: false, status: 'inactive' }, { ...membership, id: 22, active: false, manageable: false, status: 'revoked' }] });
        const wrapper = await render();
        expect(wrapper.text()).toContain('Example Company');
        expect(wrapper.text()).toContain('Example Site');
        expect(wrapper.text()).toContain('Example Department');
        expect(wrapper.text()).toContain('Effective');
        expect(wrapper.text()).toContain('Revoked');
        await wrapper.get('select[aria-label="Language"]').setValue('lo');
        expect(wrapper.text()).toContain('ສະມາຊິກອົງກອນຂອງຜູ້ໃຊ້');
        expect(wrapper.text()).toContain('ພະແນກຕົວຢ່າງ');
        expect(wrapper.text()).toContain('ຖືກຖອນ');
    });

    it('does not load account directories or show mutation controls for read-only administrators', async () => {
        const fetch = server({ rows: [] });
        const wrapper = await render({ ...scope, permissions: ['memberships.view'] });
        expect(wrapper.text()).toContain('No records to display.');
        expect(wrapper.text()).not.toContain('Assign membership');
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('shows loading state before membership data arrives', async () => {
        let resolve;
        server({ delay: new Promise(done => { resolve = done; }) });
        const wrapper = await render();
        expect(wrapper.get('table').attributes('aria-busy')).toBe('true');
        expect(wrapper.text()).toContain('Loading');
        resolve();
        await flushPromises();
        expect(wrapper.get('table').attributes('aria-busy')).toBe('false');
    });

    it('requires confirmation identifying the account and unit and sends explicit scoped data with CSRF', async () => {
        const fetch = server();
        const wrapper = await render();
        await button(wrapper, 'Find eligible accounts and units').trigger('click');
        await flushPromises();
        await control(wrapper, 'Account').setValue(9);
        await control(wrapper, 'Organizational unit').setValue(7);
        await wrapper.get('form').trigger('submit');
        await flushPromises();
        expect(wrapper.get('dialog').text()).toContain('member@example.test');
        expect(wrapper.get('dialog').text()).toContain('Example Company / Example Site');
        expect(fetch.mock.calls.some(([, options]) => options.method === 'POST')).toBe(false);
        await button(wrapper.get('dialog'), 'Confirm').trigger('click');
        await flushPromises();
        const [, options] = fetch.mock.calls.find(([, options]) => options.method === 'POST');
        expect(options.headers['X-CSRF-TOKEN']).toBe('sample-csrf');
        expect(options.credentials).toBe('same-origin');
        expect(JSON.parse(options.body)).toEqual({ scope_type: 'site', company_id: 4, site_id: 7, user_id: 9, unit_type: 'site', unit_company_id: 4, unit_site_id: 7, unit_department_id: null, confirm_regrant: false });
        expect(wrapper.text()).toContain('Organization membership updated.');
    });

    it('updates active status and never offers mutation actions on terminal or self-protected rows', async () => {
        const fetch = server({ rows: [membership, { ...membership, id: 21, manageable: false }] });
        const wrapper = await render();
        expect(wrapper.findAll('button').filter(button => button.text() === 'Deactivate')).toHaveLength(1);
        await button(wrapper, 'Deactivate').trigger('click');
        await flushPromises();
        await button(wrapper.get('dialog'), 'Confirm').trigger('click');
        await flushPromises();
        const [url, options] = fetch.mock.calls.find(([, options]) => options.method === 'PATCH');
        expect(url).toBe('/memberships/20');
        expect(JSON.parse(options.body)).toMatchObject({ active: false, site_id: 7 });
    });

    it('cancels revocation without sending a mutation and confirms a later revocation', async () => {
        const fetch = server();
        const wrapper = await render();
        await button(wrapper, 'Revoke membership').trigger('click');
        await flushPromises();
        await button(wrapper.get('dialog'), 'Cancel').trigger('click');
        expect(fetch.mock.calls.some(([, options]) => options.method === 'DELETE')).toBe(false);
        await button(wrapper, 'Revoke membership').trigger('click');
        await flushPromises();
        await button(wrapper.get('dialog'), 'Confirm').trigger('click');
        await flushPromises();
        expect(fetch.mock.calls.some(([url, options]) => url === '/memberships/20' && options.method === 'DELETE')).toBe(true);
    });

    it('shows server validation errors and removes stale controls after authority is revoked', async () => {
        server({ error: { status: 403, data: { message: 'rbacForbidden' } } });
        const wrapper = await render();
        await button(wrapper, 'Revoke membership').trigger('click');
        await flushPromises();
        await button(wrapper.get('dialog'), 'Confirm').trigger('click');
        await flushPromises();
        expect(wrapper.get('[role="alert"]').text()).toContain('You do not have permission');
        expect(wrapper.text()).not.toContain('Assign membership');
        expect(wrapper.text()).not.toContain('Revoke membership');
    });

    it('requires an explicit regrant choice and displays a server refusal in Lao', async () => {
        server({ error: { status: 422, data: { errors: { confirm_regrant: ['membershipConfirmRegrant'] } } } });
        const wrapper = await render();
        await button(wrapper, 'Revoke membership').trigger('click');
        await flushPromises();
        await button(wrapper.get('dialog'), 'Confirm').trigger('click');
        await flushPromises();
        expect(wrapper.get('[role="alert"]').text()).toContain('Explicit confirmation');
        await wrapper.get('select[aria-label="Language"]').setValue('lo');
        expect(wrapper.get('[role="alert"]').text()).toContain('ຕ້ອງຢືນຢັນ');
    });

    it('clears candidates when changing membership level or administrative scope', async () => {
        server();
        const wrapper = await render();
        await button(wrapper, 'Find eligible accounts and units').trigger('click');
        await flushPromises();
        expect(wrapper.text()).toContain('member@example.test');
        await control(wrapper, 'Membership scope').setValue('department');
        expect(wrapper.findAll('option').some(option => option.text().includes('member@example.test'))).toBe(false);
        await wrapper.setProps({ scope: { ...scope, scope_key: 'site:10', site_id: 10 } });
        await flushPromises();
        expect(wrapper.findAll('option').some(option => option.text().includes('member@example.test'))).toBe(false);
    });

    it('sends regrant authorization only when the administrator explicitly selects it', async () => {
        const fetch = server();
        const wrapper = await render();
        await button(wrapper, 'Find eligible accounts and units').trigger('click');
        await flushPromises();
        await control(wrapper, 'Account').setValue(9);
        await control(wrapper, 'Organizational unit').setValue(7);
        await wrapper.get('input[type="checkbox"]').setValue(true);
        await wrapper.get('form').trigger('submit');
        await flushPromises();
        await button(wrapper.get('dialog'), 'Confirm').trigger('click');
        await flushPromises();

        const [, options] = fetch.mock.calls.find(([, options]) => options.method === 'POST');
        expect(JSON.parse(options.body).confirm_regrant).toBe(true);
    });

    it('reactivates an eligible non-revoked membership through an explicit confirmation', async () => {
        const fetch = server({ rows: [{ ...membership, active: false, effective: false, status: 'inactive', activatable: true }] });
        const wrapper = await render();
        await button(wrapper, 'Reactivate').trigger('click');
        await flushPromises();
        await button(wrapper.get('dialog'), 'Confirm').trigger('click');
        await flushPromises();

        const [, options] = fetch.mock.calls.find(([, options]) => options.method === 'PATCH');
        expect(JSON.parse(options.body).active).toBe(true);
    });

    it('ignores candidate responses belonging to a previously selected scope', async () => {
        const fetch = server();
        const implementation = fetch.getMockImplementation();
        let finishLookup;
        const lookup = new Promise(resolve => { finishLookup = resolve; });
        fetch.mockImplementation(async (url, options) => {
            if (new URL(url, 'http://localhost').pathname === endpoints.membershipUsers) { return await lookup; }
            return implementation(url, options);
        });
        const wrapper = await render();
        await button(wrapper, 'Find eligible accounts and units').trigger('click');
        await wrapper.setProps({ scope: { ...scope, scope_key: 'site:10', site_id: 10 } });
        finishLookup(response({ data: [user] }));
        await flushPromises();

        expect(wrapper.findAll('option').some(option => option.text().includes('member@example.test'))).toBe(false);
    });
});
