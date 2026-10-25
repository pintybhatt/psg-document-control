import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import AdministrationPage from '../../resources/js/pages/AdministrationPage.vue';
import LanguageSwitcher from '../../resources/js/components/LanguageSwitcher.vue';
import App from '../../resources/js/App.vue';
import { createLocalization } from '../../resources/js/localization/index.js';

const endpoints = { contexts: '/api/v1/authorization/contexts', roles: '/api/v1/roles', permissions: '/api/v1/permissions', users: '/api/v1/role-assignment-users', assignments: '/api/v1/role-assignments' };
const allKeys = ['users.view', 'roles.view', 'roles.manage', 'assignments.view', 'assignments.manage'];
const globalScope = { scope_key: 'global', scope_type: 'global', company_id: null, site_id: null, company: null, site: null, permissions: allKeys };
const permissionRows = [
    { key: 'roles.view', area: 'roles', name_en: 'View roles', name_lo: 'ເບິ່ງບົດບາດ', available: true, delegatable: true },
    { key: 'roles.manage', area: 'roles', name_en: 'Manage roles', name_lo: 'ຈັດການບົດບາດ', available: true, delegatable: true },
    { key: 'documents.register', area: 'documents', name_en: 'Register documents (planned)', name_lo: 'ລົງທະບຽນເອກະສານ (ຢູ່ໃນແຜນ)', available: false, delegatable: false },
];
const sampleRole = { id: 10, code: 'EXAMPLE_ROLE', name_en: 'Example Role', name_lo: 'ບົດບາດຕົວຢ່າງ', description: 'Fictional configuration', active: true, scope_key: 'global', permission_keys: ['roles.view'], editable: true, security_editable: true };
const sampleAssignment = { id: 20, user: { id: 2, name: 'Example Account', email: 'example@example.test' }, role: sampleRole, scope_key: 'global', active: true, revocable: true };
const dialogDescriptors = Object.getOwnPropertyDescriptors(HTMLDialogElement.prototype);

enableAutoUnmount(afterEach);
beforeAll(() => {
    Object.defineProperties(HTMLDialogElement.prototype, {
        showModal: { configurable: true, value() { this.setAttribute('open', ''); } },
        close: { configurable: true, value() { if (this.open) { this.removeAttribute('open'); this.dispatchEvent(new Event('close')); } } },
    });
});
afterAll(() => {
    for (const name of ['showModal', 'close']) {
        if (dialogDescriptors[name]) { Object.defineProperty(HTMLDialogElement.prototype, name, dialogDescriptors[name]); }
        else { delete HTMLDialogElement.prototype[name]; }
    }
});
beforeEach(() => localStorage.clear());
afterEach(() => { document.body.replaceChildren(); vi.unstubAllGlobals(); });

function response(data, status = 200) { return { ok: status < 400, status, json: async () => structuredClone(data) }; }

function server({ contexts = [globalScope], roles = [sampleRole], assignments = [], mutationError = null } = {}) {
    const roleState = structuredClone(roles);
    const assignmentState = structuredClone(assignments);
    const fetch = vi.fn(async (url, options = {}) => {
        const path = new URL(url, 'http://localhost').pathname;
        const method = options.method ?? 'GET';
        if (method !== 'GET' && mutationError) { return response(mutationError.body, mutationError.status); }
        if (path === endpoints.contexts) { return response({ data: contexts }); }
        if (path === endpoints.permissions) { return response({ data: permissionRows }); }
        if (path === endpoints.users) { return response({ data: [sampleAssignment.user], total: 1 }); }
        if (path === endpoints.roles && method === 'GET') { return response({ data: roleState, meta: { total: roleState.length } }); }
        if (path.startsWith(endpoints.roles) && ['POST', 'PATCH'].includes(method)) {
            const body = JSON.parse(options.body);
            const role = { ...sampleRole, ...body, id: method === 'POST' ? 11 : sampleRole.id };
            if (method === 'POST') { roleState.push(role); } else { Object.assign(roleState[0], role); }
            return response({ data: role }, method === 'POST' ? 201 : 200);
        }
        if (path === endpoints.assignments && method === 'GET') { return response({ data: assignmentState, meta: { total: assignmentState.length } }); }
        if (path === endpoints.assignments && method === 'POST') {
            assignmentState.push(structuredClone(sampleAssignment));
            return response({ data: sampleAssignment }, 201);
        }
        if (path.startsWith(`${endpoints.assignments}/`) && method === 'DELETE') {
            assignmentState[0].active = false;
            return response(null, 204);
        }
        throw new Error(`Unexpected test request: ${method} ${path}`);
    });
    vi.stubGlobal('fetch', fetch);
    return fetch;
}

async function render() {
    const wrapper = mount({
        components: { AdministrationPage, LanguageSwitcher },
        setup: () => ({ endpoints }),
        template: '<div id="dcms-app"><LanguageSwitcher /><AdministrationPage :endpoints="endpoints" csrf-token="sample-csrf" /></div>',
    }, { attachTo: document.body, global: { plugins: [createLocalization()] } });
    await flushPromises();
    return wrapper;
}

function button(wrapper, text) {
    const found = wrapper.findAll('button').find(item => item.text() === text);
    expect(found, `Button ${text}`).toBeDefined();
    return found;
}

function control(wrapper, label) {
    const fieldLabel = wrapper.findAll('label').find(item => item.text().replace('(required)', '').replace('*', '').trim() === label);
    expect(fieldLabel, `Label ${label}`).toBeDefined();
    return wrapper.get(`#${fieldLabel.attributes('for')}`);
}

async function newRole(wrapper) {
    await button(wrapper, 'Create role').trigger('click');
    await wrapper.get('input[name="role-code"]').setValue('NEW_ROLE');
    await wrapper.get('input[name="role-name-en"]').setValue('New Role');
    await wrapper.get('input[name="role-name-lo"]').setValue('ບົດບາດໃໝ່');
}

describe('scoped role administration', () => {
    it('loads the Administration section through the existing authenticated application shell', async () => {
        server({ contexts: [] });
        const wrapper = mount(App, {
            props: {
                page: 'administration', navigation: [{ key: 'dashboard', href: '/dashboard' }, { key: 'administration', href: '/administration' }],
                context: { company: { name: 'Phonesack Group' }, site: null },
                auth: { csrfToken: 'sample-csrf', logoutUrl: '/logout' }, administration: endpoints,
            }, global: { plugins: [createLocalization()] },
        });
        await vi.dynamicImportSettled();
        await flushPromises();

        expect(wrapper.find('aside').exists()).toBe(true);
        expect(wrapper.get('h1').text()).toBe('Administration');
        expect(wrapper.text()).toContain('You have no administrative permissions.');
        expect(wrapper.get('form[action="/logout"]').exists()).toBe(true);
    });
    it('shows a safe no-permission state without requesting users or roles', async () => {
        const fetch = server({ contexts: [] });
        const wrapper = await render();

        expect(wrapper.text()).toContain('You have no administrative permissions.');
        expect(wrapper.find('table').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Create role');
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('shows empty states and hides all mutation controls for read-only scope access', async () => {
        server({ contexts: [{ ...globalScope, permissions: ['roles.view', 'assignments.view'] }], roles: [], assignments: [] });
        const wrapper = await render();

        expect(wrapper.findAll('table')).toHaveLength(2);
        expect(wrapper.text()).toContain('No records to display.');
        expect(wrapper.text()).not.toContain('Create role');
        expect(wrapper.text()).not.toContain('Find eligible accounts and roles');
    });

    it('switches bilingual headings, scope messages, permission labels and role names', async () => {
        server();
        const wrapper = await render();
        await button(wrapper, 'Edit').trigger('click');
        await wrapper.get('select[aria-label="Language"]').setValue('lo');

        expect(wrapper.text()).toContain('ບົດບາດ ແລະ ສິດ');
        expect(wrapper.text()).toContain('ບົດບາດຕົວຢ່າງ');
        expect(wrapper.text()).toContain('ເບິ່ງບົດບາດ');
        expect(localStorage.getItem('dcms.language')).toBe('lo');
    });

    it('shows concise inactive status labels for roles and assignments in both languages', async () => {
        server({ roles: [{ ...sampleRole, active: false }], assignments: [{ ...sampleAssignment, active: false }] });
        const wrapper = await render();

        expect(wrapper.findAll('td').filter(cell => cell.text() === 'Inactive')).toHaveLength(2);
        await wrapper.get('select[aria-label="Language"]').setValue('lo');
        expect(wrapper.findAll('td').filter(cell => cell.text() === 'ປິດການໃຊ້ງານ')).toHaveLength(2);
    });

    it('creates a bilingual role using catalog permissions and CSRF while keeping planned capabilities disabled', async () => {
        const fetch = server();
        const wrapper = await render();
        await newRole(wrapper);
        await wrapper.get('input[value="roles.view"]').setValue(true);
        expect(wrapper.get('input[value="documents.register"]').element.disabled).toBe(true);
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        const [, options] = fetch.mock.calls.find(([url, options]) => url === endpoints.roles && options.method === 'POST');
        expect(options.headers['X-CSRF-TOKEN']).toBe('sample-csrf');
        expect(options.credentials).toBe('same-origin');
        expect(JSON.parse(options.body)).toMatchObject({ code: 'NEW_ROLE', name_lo: 'ບົດບາດໃໝ່', permission_keys: ['roles.view'], scope_type: 'global', company_id: null, site_id: null });
        expect(wrapper.text()).toContain('Role configuration saved.');
        expect(wrapper.text()).toContain('New Role');
    });

    it('opens inherited definitions read-only and locks security settings for the current users own role', async () => {
        server({ roles: [{ ...sampleRole, editable: false }] });
        const wrapper = await render();
        await button(wrapper, 'Details').trigger('click');

        expect(wrapper.get('input[name="role-name-en"]').element.readOnly).toBe(true);
        expect(wrapper.get('input[value="roles.view"]').element.disabled).toBe(true);
        expect(wrapper.text()).not.toContain('Save role');
        expect(wrapper.text()).toContain('This shared definition can be edited only');
    });

    it('locks permissions and active status on an assigned role while allowing display metadata edits', async () => {
        const fetch = server({ roles: [{ ...sampleRole, security_editable: false }] });
        const wrapper = await render();
        await button(wrapper, 'Edit').trigger('click');

        expect(wrapper.get('input[value="roles.view"]').element.disabled).toBe(true);
        expect(wrapper.findAll('input[type="checkbox"]').every(input => input.element.disabled)).toBe(true);
        expect(wrapper.text()).toContain('You may edit display information');
        await wrapper.get('input[name="role-name-en"]').setValue('Updated display name');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        const [, options] = fetch.mock.calls.find(([, options]) => options.method === 'PATCH');
        expect(JSON.parse(options.body)).toMatchObject({ name_en: 'Updated display name', active: true, permission_keys: ['roles.view'] });
    });

    it('requires explicit scoped confirmation before assigning a role', async () => {
        const fetch = server();
        const wrapper = await render();
        await button(wrapper, 'Find eligible accounts and roles').trigger('click');
        await flushPromises();
        await control(wrapper, 'Account').setValue(2);
        await control(wrapper, 'Role').setValue(10);
        await button(wrapper, 'Assign role').trigger('click');
        await flushPromises();
        expect(wrapper.get('dialog').text()).toContain('Group / Global');
        expect(wrapper.get('dialog').text()).toContain('Example Account (example@example.test)');
        expect(wrapper.get('dialog').text()).toContain('Example Role (EXAMPLE_ROLE)');
        expect(fetch.mock.calls.some(([, options]) => options.method === 'POST')).toBe(false);
        await button(wrapper.get('dialog'), 'Confirm').trigger('click');
        await flushPromises();

        const [, options] = fetch.mock.calls.find(([url, options]) => url === endpoints.assignments && options.method === 'POST');
        expect(JSON.parse(options.body)).toMatchObject({ user_id: 2, role_id: 10, scope_type: 'global', confirm_global: true });
        expect(wrapper.text()).toContain('Role assignment updated.');
    });

    it('supports revocation confirmation and does not show a revoke control for protected rows', async () => {
        const fetch = server({ assignments: [sampleAssignment, { ...sampleAssignment, id: 21, revocable: false }] });
        const wrapper = await render();
        expect(wrapper.findAll('button').filter(item => item.text() === 'Revoke role')).toHaveLength(1);
        await button(wrapper, 'Revoke role').trigger('click');
        await flushPromises();
        expect(wrapper.get('dialog').text()).toContain('Example Account (example@example.test)');
        expect(wrapper.get('dialog').text()).toContain('Example Role (EXAMPLE_ROLE)');
        await wrapper.get('dialog').trigger('cancel');
        await flushPromises();
        expect(fetch.mock.calls.some(([, options]) => options.method === 'DELETE')).toBe(false);
        await button(wrapper, 'Revoke role').trigger('click');
        await flushPromises();
        await button(wrapper.get('dialog'), 'Confirm').trigger('click');
        await flushPromises();

        expect(fetch.mock.calls.some(([url, options]) => url === `${endpoints.assignments}/20` && options.method === 'DELETE')).toBe(true);
        expect(wrapper.text()).toContain('Role assignment updated.');
    });

    it('removes stale mutation controls when the server revokes authority', async () => {
        server({ mutationError: { status: 403, body: { message: 'This action is unauthorized.' } } });
        const wrapper = await render();
        await newRole(wrapper);
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toContain('You do not have permission');
        expect(wrapper.text()).not.toContain('Create role');
        expect(wrapper.text()).not.toContain('Find eligible accounts and roles');
    });

    it('shows the final-administrator protection error in the selected language', async () => {
        server({ mutationError: { status: 422, body: { errors: { authorization: ['rbacLastAdministrator'] } } } });
        const wrapper = await render();
        await newRole(wrapper);
        await wrapper.get('form').trigger('submit');
        await flushPromises();
        expect(wrapper.get('[role="alert"]').text()).toContain('final active security administrator');
        await wrapper.get('select[aria-label="Language"]').setValue('lo');
        expect(wrapper.get('[role="alert"]').text()).toContain('ຜູ້ບໍລິຫານ');
    });

    it('closes confirmation and exposes a revocation rejection instead of leaving the error behind a modal', async () => {
        server({ assignments: [sampleAssignment], mutationError: { status: 422, body: { errors: { authorization: ['rbacLastAdministrator'] } } } });
        const wrapper = await render();
        await button(wrapper, 'Revoke role').trigger('click');
        await flushPromises();
        await button(wrapper.get('dialog'), 'Confirm').trigger('click');
        await flushPromises();

        expect(wrapper.get('dialog').element.open).toBe(false);
        expect(wrapper.get('[role="alert"]').text()).toContain('final active security administrator');
    });

    it('clears account candidates and edits when the scope changes', async () => {
        const companyScope = { ...globalScope, scope_key: 'company:7', scope_type: 'company', company_id: 7, company: { name_en: 'Example Company', name_lo: 'ບໍລິສັດຕົວຢ່າງ' }, permissions: ['roles.view'] };
        const fetch = server({ contexts: [globalScope, companyScope] });
        const wrapper = await render();
        await button(wrapper, 'Find eligible accounts and roles').trigger('click');
        await flushPromises();
        await newRole(wrapper);
        await control(wrapper, 'Authorization scope').setValue('company:7');
        await flushPromises();

        expect(wrapper.find('input[name="role-code"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('example@example.test');
        expect(fetch.mock.calls.some(([url]) => url.includes('scope_type=company') && url.includes('company_id=7'))).toBe(true);
        expect(wrapper.text()).not.toContain('Create role');
    });
});
