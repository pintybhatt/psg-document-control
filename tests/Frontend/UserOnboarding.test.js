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
const role = { ...names(10, 'Document Controller'), code: 'DOCUMENT_CONTROLLER', active: true, assignable: true, permission_keys: ['organization.access', 'documents.metadata.view'] };
const existingUser = { id: 2, name: 'Existing User', email: 'existing@example.test', preferred_language: 'en', active: true };

function server({ user = null, failSave = false } = {}) {
    const users = user ? [structuredClone(user)] : [];
    const currentAccess = { memberships: [{ ...names(20, 'Membership'), company: names(1, 'Example Company'), site: names(2, 'Existing Site'), effective: true, revoke_url: '/memberships/20?scope_type=site' }],
        assignments: [{ id: 30, role, scope_key: 'site:2', effective: true, revoke_url: '/assignments/30?scope_type=site' }] };
    const response = (data, status = 200) => ({ ok: status < 400, status, json: async () => structuredClone(data) });
    const fetch = vi.fn(async (url, options = {}) => {
        const parsed = new URL(url, 'http://localhost');
        const method = options.method ?? 'GET';
        if (method === 'DELETE') {
            currentAccess.memberships = [];
            return response(null, 204);
        }
        if (method !== 'GET') {
            if (failSave) { return response({ errors: { 'access.role_id': ['userAccessInvalidRole'] } }, 422); }
            const body = JSON.parse(options.body);
            if (method === 'POST') { users.push({ ...existingUser, name: body.name, email: body.email }); }
            return response({ data: users[0] }, method === 'POST' ? 201 : 200);
        }
        if (parsed.pathname === '/units') {
            const type = parsed.searchParams.get('unit_type');
            const rows = type === 'company' ? [names(1, 'Example Company'), names(4, 'Other Company')]
                : type === 'site' ? [names(parsed.searchParams.get('company_id') === '1' ? 2 : 5, 'Selected Site')]
                    : [names(3, 'Example Department')];
            return response({ data: rows, last_page: 1 });
        }
        if (parsed.pathname === '/roles') {
            return response({ data: [role, { ...role, id: 14, code: 'DEPARTMENT_USER' }, { ...role, id: 11, permission_keys: [] }, { ...role, id: 12, code: 'SECURITY_ADMINISTRATOR' }, { ...role, id: 13, active: false }], meta: { last_page: 1 } });
        }
        if (parsed.pathname === '/permissions') { return response({ data: role.permission_keys.map(key => ({ key, available: true, delegatable: true })) }); }
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
    await field(wrapper, 'Site').setValue(2); await flushPromises();
    await field(wrapper, 'Role').setValue(10);
}

describe('combined user onboarding', () => {
    it.each([false, true])('submits account, exact membership and role together (department %s)', async department => {
        const fetch = server();
        const wrapper = await render();
        await button(wrapper, 'Create user').trigger('click'); await flushPromises();
        await field(wrapper, 'Name').setValue('Example User');
        await field(wrapper, 'Email').setValue('example@example.test');
        await field(wrapper, 'Initial password').setValue('SecurePass123!');
        await field(wrapper, 'Confirm password').setValue('SecurePass123!');
        await selectSite(wrapper);
        if (department) {
            await field(wrapper, 'Department').setValue(3);
            expect(field(wrapper, 'Role').element.value).toBe('');
            await field(wrapper, 'Role').setValue(14);
        }
        expect(field(wrapper, 'Role').findAll('option')).toHaveLength(department ? 2 : 3);
        await wrapper.findAll('form')[1].trigger('submit'); await flushPromises();
        const mutations = fetch.mock.calls.filter(([, options]) => options.method !== 'GET');
        expect(mutations).toHaveLength(1);
        expect(mutations[0][1].method).toBe('POST');
        expect(JSON.parse(mutations[0][1].body).access).toEqual({ company_id: 1, site_id: 2, department_id: department ? 3 : null, role_id: department ? 14 : 10, confirm_regrant: false });
    });

    it('clears dependent choices when company or site changes', async () => {
        server();
        const wrapper = await render();
        await button(wrapper, 'Create user').trigger('click'); await flushPromises();
        await selectSite(wrapper);
        await field(wrapper, 'Department').setValue(3);
        await field(wrapper, 'Company').setValue(4); await flushPromises();
        expect(field(wrapper, 'Site').element.value).toBe('');
        expect(field(wrapper, 'Department').element.value).toBe('');
        expect(field(wrapper, 'Role').element.value).toBe('');
        expect(field(wrapper, 'Site').findAll('option').map(item => item.element.value)).toEqual(['', '5']);
    });

    it('shows existing access and sends only an explicit addition when editing', async () => {
        const fetch = server({ user: existingUser });
        const wrapper = await render();
        await button(wrapper, 'Edit').trigger('click'); await flushPromises();
        expect(wrapper.text()).toContain('Existing Site');
        expect(wrapper.text()).toContain('site:2');
        await selectSite(wrapper);
        await wrapper.findAll('form')[1].trigger('submit'); await flushPromises();
        const updates = fetch.mock.calls.filter(([, options]) => options.method !== 'GET');
        expect(updates).toHaveLength(1);
        expect(updates[0][0]).toBe('/users/2');
        expect(updates[0][1].method).toBe('PATCH');
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
        expect(wrapper.text()).toContain('site:2');
    });

    it('keeps the editor open and explains incompatible role validation', async () => {
        server({ user: existingUser, failSave: true });
        const wrapper = await render();
        await button(wrapper, 'Edit').trigger('click'); await flushPromises();
        await selectSite(wrapper);
        await wrapper.findAll('form')[1].trigger('submit'); await flushPromises();
        expect(wrapper.get('[role="alert"]').text()).toContain('active, authorized workspace role');
        expect(wrapper.findAll('form')).toHaveLength(2);
    });

    it('translates the onboarding and current-access controls into Lao', async () => {
        server({ user: existingUser });
        const wrapper = await render('lo');
        await button(wrapper, 'ແກ້ໄຂ').trigger('click'); await flushPromises();
        expect(wrapper.text()).toContain('ເພີ່ມສິດເຂົ້າໃຊ້ອົງກອນ');
        expect(wrapper.text()).toContain('ສະມາຊິກ ແລະ ບົດບາດປັດຈຸບັນ');
        expect(wrapper.text()).not.toContain('userAccess');
    });
});
