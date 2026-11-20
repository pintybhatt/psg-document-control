import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import UserManagement from '../../resources/js/components/UserManagement.vue';
import { createLocalization } from '../../resources/js/localization/index.js';

enableAutoUnmount(afterEach);
beforeEach(() => localStorage.clear());
afterEach(() => vi.unstubAllGlobals());

const endpoint = '/users';
const accessEndpoints = { units: '/units', roles: '/roles', permissions: '/permissions' };
const names = (id, name) => ({ id, name_en: name, name_lo: `ຕົວຢ່າງ ${id}` });
const role = { ...names(10, 'Document Controller'), code: 'DOCUMENT_CONTROLLER', description: 'Manages document registers.', scope_key: 'global', active: true, assignable: true, permission_keys: [] };
const companyRole = { ...role, ...names(14, 'Company Viewer'), code: 'COMPANY_VIEWER', scope_key: 'company:1', permission_keys: ['organization.access'] };
const siteRole = { ...role, ...names(11, 'Site Viewer'), code: 'SITE_VIEWER', scope_key: 'site:2' };
const departmentRole = { ...role, ...names(16, 'Department User'), code: 'DEPARTMENT_USER' };
const viewerRole = { ...role, ...names(17, 'Viewer'), code: 'VIEWER' };
const existingUser = { id: 2, name: 'Existing User', email: 'existing@example.test', preferred_language: 'en', active: true };

function server({ user = null, failSave = false, noAssignableRoles = false, failRoles = false, failEmail = false, legacyStandard = false } = {}) {
    const users = user ? [structuredClone(user)] : [];
    const currentAccess = { memberships: [{ ...names(20, 'Membership'), company: names(1, 'Example Company'), site: names(2, 'Existing Site'), effective: true, revoke_url: '/memberships/20?scope_type=site' }],
        assignments: [{ id: 30, role, company: names(1, 'Example Company'), site: names(2, 'Existing Site'), scope_key: 'site:2', effective: true, revoke_url: '/assignments/30?scope_type=site' }] };
    const response = (data, status = 200) => ({ ok: status < 400, status, json: async () => structuredClone(data) });
    const fetch = vi.fn(async (url, options = {}) => {
        const parsed = new URL(url, 'http://localhost');
        const method = options.method ?? 'GET';
        if (method === 'DELETE') {
            currentAccess.memberships = [];
            return response(null, 204);
        }
        if (method !== 'GET') {
            if (legacyStandard) { return response({ errors: { 'access.role_id': ['userAccessStandardWorkspaceMissing'] } }, 422); }
            if (failSave) { return response({ errors: { 'access.role_id': ['userAccessInvalidRole'] } }, 422); }
            if (failEmail) { return response({ errors: { email: ['The email has already been taken.'] } }, 422); }
            const body = JSON.parse(options.body);
            if (method === 'POST') { users.push({ ...existingUser, name: body.name, email: body.email }); }
            return response({ data: users[0] }, method === 'POST' ? 201 : 200);
        }
        if (parsed.pathname === '/units') {
            const type = parsed.searchParams.get('unit_type');
            const rows = type === 'company' ? [names(1, 'Example Company'), names(4, 'Other Company')]
                : type === 'site' ? [names(parsed.searchParams.get('company_id') === '1' ? 2 : 5, 'Selected Site')]
                    : [{ ...names(3, 'Example Department'), site: names(2, 'Selected Site') }, { ...names(6, 'Another Department'), site: names(2, 'Selected Site') }];
            return response({ data: rows, last_page: 1 });
        }
        if (parsed.pathname === '/roles') {
            if (failRoles) { return response({}, 500); }
            const excluded = [{ ...role, id: 12, code: 'SECURITY_ADMINISTRATOR' }, { ...role, id: 13, active: false }, { ...role, id: 15, permission_keys: ['documents.view'] }];
            const controller = { ...role, standard: true, permission_keys: legacyStandard ? [] : ['documents.metadata.view'] };
            const candidates = parsed.searchParams.get('scope_type') === 'site' ? [controller, companyRole, siteRole, departmentRole, viewerRole] : [controller, companyRole, departmentRole, viewerRole];
            return response({ data: noAssignableRoles ? excluded : [...candidates, ...excluded], meta: { last_page: 1 } });
        }
        if (parsed.pathname === '/permissions') { return response({ data: ['organization.access', 'documents.metadata.view'].map(key => ({ key, available: true, delegatable: true })).concat({ key: 'documents.view', available: false, delegatable: false }) }); }
        if (parsed.pathname.endsWith('/access')) { return response(currentAccess); }
        return response({ data: users, last_page: 1 });
    });
    vi.stubGlobal('fetch', fetch);
    return fetch;
}

async function render(language = 'en') {
    const wrapper = mount(UserManagement, { props: { endpoint, accessEndpoints, csrfToken: 'test-csrf', currentUserId: 1 },
        global: { plugins: [createLocalization(language)], stubs: { ConfirmDialog: { props: ['message'], emits: ['confirm'], template: '<div>{{ message }}<button @click="$emit(\'confirm\')">Confirm revoke</button></div>' } } } });
    await flushPromises();
    return wrapper;
}

function button(wrapper, text) { return wrapper.findAll('button').find(item => item.text() === text); }
function field(wrapper, name) {
    const label = wrapper.findAll('label').find(item => item.text().startsWith(name));
    return wrapper.get(`#${label.attributes('for')}`);
}
async function selectSite(wrapper) {
    await field(wrapper, 'Company').setValue(1); await flushPromises();
    await field(wrapper, 'Access scope').setValue(2); await flushPromises();
    await field(wrapper, 'Role').setValue(10);
}

describe('combined user onboarding', () => {
    it.each([false, true])('shows the empty message only after a successful role lookup (lookup failed %s)', async failRoles => {
        const fetch = server({ noAssignableRoles: true, failRoles });
        const wrapper = await render();
        await button(wrapper, 'Create user').trigger('click'); await flushPromises();
        await field(wrapper, 'Company').setValue(1); await flushPromises();
        await field(wrapper, 'Access scope').setValue(2); await flushPromises();
        expect(field(wrapper, 'Role').findAll('option')).toHaveLength(1);
        expect(wrapper.text().includes('No roles can be assigned for this selection.')).toBe(!failRoles);
        expect(wrapper.find('[role="alert"]').exists()).toBe(failRoles);
        expect(fetch.mock.calls.every(([, options]) => options.method === 'GET')).toBe(true);
    });

    it('shows searchable roles from the selected site and its parent scopes without requiring metadata access', async () => {
        server();
        const wrapper = await render();
        await button(wrapper, 'Create user').trigger('click'); await flushPromises();
        await selectSite(wrapper);
        expect(wrapper.text()).toContain('Manages document registers.');
        const roleField = field(wrapper, 'Role');
        expect(roleField.findAll('option').slice(1).map(option => option.text())).toEqual([
            'Document Controller · Group / Global', 'Company Viewer · Company', 'Site Viewer · Site', 'Department User · Group / Global', 'Viewer · Group / Global',
        ]);

        const combobox = wrapper.get(`[role="combobox"][aria-controls="select2-${roleField.element.id}-container"]`);
        combobox.element.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', which: 13, bubbles: true }));
        const search = document.querySelector('.select2-container--open .select2-search__field');
        expect(search).not.toBeNull();
        search.value = 'Company Viewer';
        search.dispatchEvent(new Event('input', { bubbles: true }));
        expect(Array.from(document.querySelectorAll('.select2-results__option')).map(option => option.textContent)).toEqual(['Company Viewer · Company']);
    });
    it.each(['all', 'selected', 'site_wide'])('submits account and %s department coverage with the selected role', async mode => {
        const fetch = server();
        const wrapper = await render();
        await button(wrapper, 'Create user').trigger('click'); await flushPromises();
        await field(wrapper, 'Name').setValue('Example User');
        await field(wrapper, 'Email').setValue('example@example.test');
        await field(wrapper, 'Initial password').setValue('SecurePass123!');
        await field(wrapper, 'Confirm password').setValue('SecurePass123!');
        await selectSite(wrapper);
        expect(field(wrapper, 'Department access').element.value).toBe('all');
        await field(wrapper, 'Department access').setValue(mode);
        if (mode === 'selected') {
            expect(button(wrapper, 'Save user').element.disabled).toBe(true);
            await field(wrapper, 'Example Department').setValue(true);
            await field(wrapper, 'Another Department').setValue(true);
        }
        expect(field(wrapper, 'Role').element.value).toBe('10');
        await wrapper.findAll('form')[1].trigger('submit'); await flushPromises();
        const mutations = fetch.mock.calls.filter(([, options]) => options.method !== 'GET');
        expect(mutations).toHaveLength(1);
        expect(mutations[0][1].method).toBe('POST');
        expect(JSON.parse(mutations[0][1].body).access).toEqual({
            company_id: 1, site_id: 2, department_access: mode, role_id: 10, confirm_regrant: false,
            ...(mode === 'selected' ? { department_ids: [3, 6] } : {}),
        });
    });

    it('clears dependent choices when company or site changes', async () => {
        server();
        const wrapper = await render();
        await button(wrapper, 'Create user').trigger('click'); await flushPromises();
        await selectSite(wrapper);
        await field(wrapper, 'Department access').setValue('selected');
        await field(wrapper, 'Example Department').setValue(true);
        await field(wrapper, 'Company').setValue(4); await flushPromises();
        expect(field(wrapper, 'Access scope').element.value).toBe('');
        expect(wrapper.text()).not.toContain('Department access');
        expect(field(wrapper, 'Role').element.value).toBe('');
        expect(field(wrapper, 'Access scope').findAll('option').map(item => item.element.value)).toEqual(['', 'company', '5']);
    });

    it('shows existing access and sends only an explicit addition when editing', async () => {
        const fetch = server({ user: existingUser });
        const wrapper = await render();
        await button(wrapper, 'Edit').trigger('click'); await flushPromises();
        expect(wrapper.text()).toContain('Existing Site');
        expect(wrapper.text()).toContain('Example Company / Existing Site');
        await field(wrapper, 'Company').setValue(1); await flushPromises();
        expect(field(wrapper, 'Role').element.disabled).toBe(true);
        await field(wrapper, 'Access scope').setValue('company'); await flushPromises();
        expect(field(wrapper, 'Role').findAll('option').map(option => option.element.value)).toEqual(['', '10', '14', '16', '17']);
        await field(wrapper, 'Role').setValue(17);
        expect(field(wrapper, 'Department access').element.value).toBe('all');
        await field(wrapper, 'Role').setValue(16);
        expect(field(wrapper, 'Department access').element.value).toBe('selected');
        expect(field(wrapper, 'Selected Site / Example Department').exists()).toBe(true);
        await field(wrapper, 'Role').setValue(14);
        expect(field(wrapper, 'Department access').element.value).toBe('site_wide');
        await wrapper.findAll('form')[1].trigger('submit'); await flushPromises();
        const updates = fetch.mock.calls.filter(([, options]) => options.method !== 'GET');
        expect(updates).toHaveLength(1);
        expect(updates[0][0]).toBe('/users/2');
        expect(updates[0][1].method).toBe('PATCH');
        expect(JSON.parse(updates[0][1].body).access).toEqual({ company_id: 1, site_id: null, department_access: 'site_wide', role_id: 14, confirm_regrant: false });
        expect(JSON.parse(updates[0][1].body)).not.toHaveProperty('revoke');
    });

    it('revokes only the explicitly confirmed existing membership through its existing API', async () => {
        const fetch = server({ user: existingUser });
        const wrapper = await render();
        await button(wrapper, 'Edit').trigger('click'); await flushPromises();
        await button(wrapper, 'Revoke membership').trigger('click');
        expect(fetch.mock.calls.some(([, options]) => options.method === 'DELETE')).toBe(false);
        await button(wrapper, 'Confirm revoke').trigger('click'); await flushPromises();
        const deletions = fetch.mock.calls.filter(([, options]) => options.method === 'DELETE');
        expect(deletions).toHaveLength(1);
        expect(deletions[0][0]).toBe('/memberships/20?scope_type=site');
        expect(wrapper.text()).toContain('Example Company / Existing Site');
    });

    it('keeps the editor open and explains incompatible role validation', async () => {
        server({ user: existingUser, failSave: true });
        const wrapper = await render();
        await button(wrapper, 'Edit').trigger('click'); await flushPromises();
        await selectSite(wrapper);
        await wrapper.findAll('form')[1].trigger('submit'); await flushPromises();
        expect(wrapper.get('[role="alert"]').text()).toContain('active role you can assign at this scope');
        expect(wrapper.findAll('form')).toHaveLength(2);
    });

    it('warns about a legacy standard role and shows the server rejection in Lao', async () => {
        server({ user: existingUser, legacyStandard: true });
        const wrapper = await render('lo');
        await button(wrapper, 'ແກ້ໄຂ').trigger('click'); await flushPromises();
        await field(wrapper, 'ບໍລິສັດ').setValue(1); await flushPromises();
        await field(wrapper, 'ຂອບເຂດການເຂົ້າເຖິງ').setValue(2); await flushPromises();
        await field(wrapper, 'ບົດບາດ').setValue(10);
        expect(wrapper.get('[role="status"]').text()).toContain('ຂາດສິດໃຊ້ພື້ນທີ່ເອກະສານ');
        await wrapper.findAll('form')[1].trigger('submit'); await flushPromises();
        expect(wrapper.get('[role="alert"]').text()).toContain('ຂາດສິດໃຊ້ພື້ນທີ່ເອກະສານ');
        expect(wrapper.get('[role="dialog"]').exists()).toBe(true);
    });

    it('translates the onboarding and current-access controls into Lao', async () => {
        server({ user: existingUser });
        const wrapper = await render('lo');
        await button(wrapper, 'ແກ້ໄຂ').trigger('click'); await flushPromises();
        expect(wrapper.text()).toContain('ເພີ່ມສິດເຂົ້າໃຊ້ອົງກອນ');
        expect(wrapper.text()).toContain('ສະມາຊິກ ແລະ ບົດບາດປັດຈຸບັນ');
        await field(wrapper, 'ບໍລິສັດ').setValue(1); await flushPromises();
        expect(field(wrapper, 'ຂອບເຂດການເຂົ້າເຖິງ').text()).toContain('ສິດລະດັບບໍລິສັດ');
        await field(wrapper, 'ຂອບເຂດການເຂົ້າເຖິງ').setValue('company'); await flushPromises();
        expect(field(wrapper, 'ບົດບາດ').text()).toContain(role.name_lo);
        await field(wrapper, 'ບົດບາດ').setValue(10);
        expect(field(wrapper, 'ສິດເຂົ້າເຖິງພະແນກ').text()).toContain('ທຸກພະແນກໃນຂອບເຂດນີ້');
        expect(wrapper.text()).not.toContain('userAccess');
    });

    it('shows a rejected email in Lao without changing the account API response', async () => {
        server({ user: existingUser, failEmail: true });
        const wrapper = await render('lo');
        await button(wrapper, 'ແກ້ໄຂ').trigger('click'); await flushPromises();
        await wrapper.findAll('form')[1].trigger('submit'); await flushPromises();
        const email = wrapper.get('input[type="email"]');
        expect(email.attributes('aria-invalid')).toBe('true');
        expect(wrapper.get(`#${email.attributes('aria-describedby')}`).text()).toBe('ອີເມວນີ້ຖືກໃຊ້ແລ້ວ.');
    });
});
