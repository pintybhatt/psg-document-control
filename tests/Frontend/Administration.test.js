import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import { ref } from 'vue';
import { provideWorkspace } from '../../resources/js/utilities/workspace.js';
import AdministrationPage from '../../resources/js/pages/AdministrationPage.vue';
import LanguageSwitcher from '../../resources/js/components/LanguageSwitcher.vue';
import App from '../../resources/js/App.vue';
import { createLocalization } from '../../resources/js/localization/index.js';
import { settle, swalCancel, swalConfirm, swalPopup, swalText } from './swal.js';

const endpoints = { organization: '/api/v1/organization/companies', contexts: '/api/v1/authorization/contexts', roles: '/api/v1/roles', permissions: '/api/v1/permissions', users: '/api/v1/role-assignment-users', assignments: '/api/v1/role-assignments' };
const allKeys = ['users.view', 'roles.view', 'roles.manage', 'assignments.view', 'assignments.manage'];
const globalScope = { scope_key: 'global', scope_type: 'global', company_id: null, site_id: null, company: null, site: null, permissions: allKeys };
const permissionRows = [
    { key: 'roles.view', area: 'roles', name_en: 'View roles', name_lo: 'ເບິ່ງບົດບາດ', available: true, delegatable: true },
    { key: 'roles.manage', area: 'roles', name_en: 'Manage roles', name_lo: 'ຈັດການບົດບາດ', available: true, delegatable: true },
    { key: 'documents.register', area: 'documents', name_en: 'Register documents (planned)', name_lo: 'ລົງທະບຽນເອກະສານ (ຢູ່ໃນແຜນ)', available: false, delegatable: false },
];
const recommendedProfiles = {
    SECURITY_ADMINISTRATOR: { permission_keys: ['users.manage', 'roles.view', 'roles.manage'], scope_types: ['global'] },
    DOCUMENT_CONTROLLER: { permission_keys: ['organization.access', 'documents.metadata.view', 'incoming.register'], scope_types: ['global', 'company', 'site'] },
    DEPARTMENT_USER: { permission_keys: ['organization.access', 'documents.metadata.view', 'incoming.drafts.manage'], scope_types: ['global', 'company', 'site'] },
    VIEWER: { permission_keys: ['organization.access', 'documents.metadata.view', 'reports.view'], scope_types: ['global', 'company', 'site'] },
};
const recommendationCatalog = [...permissionRows,
    ...[...new Set(Object.values(recommendedProfiles).flatMap(profile => profile.permission_keys))].filter(key => !permissionRows.some(permission => permission.key === key)).map(key => ({ key, area: 'documents', name_en: `Permission ${key}`, name_lo: `ສິດ ${key}`, available: true, delegatable: true })),
    { key: 'incoming.operations.reopen', area: 'documents', name_en: 'Reopen incoming correspondence', name_lo: 'ເປີດໜັງສືເຂົ້າຄືນ', available: true, delegatable: true },
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

function server({ contexts = [globalScope], roles = [sampleRole], assignments = [], catalog = permissionRows, recommendations = {}, mutationError = null, canManageOrganization = false } = {}) {
    const roleState = structuredClone(roles);
    const assignmentState = structuredClone(assignments);
    const fetch = vi.fn(async (url, options = {}) => {
        const path = new URL(url, 'http://localhost').pathname;
        const method = options.method ?? 'GET';
        if (method !== 'GET' && mutationError) { return response(mutationError.body, mutationError.status); }
        if (path === endpoints.contexts) { return response({ data: contexts, can_manage_organization: canManageOrganization }); }
        if (path === endpoints.organization) { return response({ data: [], meta: { total: 0 } }); }
        if (path === endpoints.permissions) { return response({ data: catalog, recommendations }); }
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

async function render(workspace = null, openRoles = true) {
    const wrapper = mount({
        components: { AdministrationPage, LanguageSwitcher },
        setup: () => { if (workspace) { provideWorkspace(workspace); } return { endpoints }; },
        template: '<div id="dcms-app"><LanguageSwitcher /><AdministrationPage :endpoints="endpoints" csrf-token="sample-csrf" /></div>',
    }, { attachTo: document.body, global: { plugins: [createLocalization()] } });
    await flushPromises();
    if (openRoles && wrapper.find('[data-admin-shortcut="roles"]').exists()) {
        await wrapper.get('[data-admin-shortcut="roles"]').trigger('click');
        await flushPromises();
    }
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
    it('opens an authorized bilingual overview and navigates to the existing roles workflow', async () => {
        const fetch = server({ contexts: [{ ...globalScope, permissions: ['roles.view'] }], roles: [{ ...sampleRole, editable: false }] });
        const wrapper = await render(null, false);
        expect(wrapper.get('[data-admin-tab="overview"]').attributes('aria-pressed')).toBe('true');
        expect(wrapper.findAll('[data-admin-shortcut]').map(item => item.attributes('data-admin-shortcut'))).toEqual(['roles']);
        await wrapper.get('[data-language-switcher] input[value="lo"]').setValue(true);
        expect(wrapper.get('#admin-overview-heading').text()).toBe('ພາບລວມ');
        await wrapper.get('[data-admin-shortcut="roles"]').trigger('click');
        expect(wrapper.get('[data-admin-tab="access"]').attributes('aria-pressed')).toBe('true');
        expect(wrapper.get('section[aria-labelledby="roles-heading"]').isVisible()).toBe(true);
        expect(fetch.mock.calls.every(([, options]) => !options.method || options.method === 'GET')).toBe(true);
    });

    it('shows only the Roles section for a custom administrator with role-view permission', async () => {
        server({ contexts: [{ ...globalScope, permissions: ['roles.view'] }], roles: [{ ...sampleRole, editable: false }] });
        const wrapper = await render();
        expect(wrapper.get('nav[aria-label="Administration"]').findAll('button').map(item => item.text())).toEqual(['Overview', 'Users & Access']);
        expect(wrapper.text()).not.toContain('Create role');
        expect(wrapper.text()).not.toContain('Assign role');
        expect(wrapper.get('section[aria-labelledby="roles-heading"]').text()).toContain('Example Role');
        expect(wrapper.findComponent({ name: 'UserManagement' }).exists()).toBe(false);
    });

    it('does not expose Users management to a delegated account permission without system authority', async () => {
        server({ contexts: [{ ...globalScope, permissions: ['users.manage', 'roles.view'] }], roles: [{ ...sampleRole, editable: false }] });
        const wrapper = await render();
        expect(wrapper.get('nav[aria-label="Administration"]').findAll('button').map(item => item.text())).toEqual(['Overview', 'Users & Access']);
        expect(wrapper.find('section[aria-labelledby="users-heading"]').exists()).toBe(false);
    });

    it.each(['DOCUMENT_CONTROLLER', 'DEPARTMENT_USER', 'VIEWER'])('previews %s recommendations and preserves custom selections until an explicit adjusted save', async code => {
        const fetch = server({ roles: [{ ...sampleRole, code, permission_keys: ['incoming.operations.reopen'] }], catalog: recommendationCatalog, recommendations: recommendedProfiles });
        const wrapper = await render();
        await button(wrapper, 'Edit').trigger('click');
        const current = wrapper.get('input[value="incoming.operations.reopen"]');
        expect(current.element.checked).toBe(true);
        for (const key of recommendedProfiles[code].permission_keys) { expect(wrapper.get(`input[value="${key}"]`).element.checked).toBe(false); }
        await button(wrapper, 'Apply Recommended Permissions').trigger('click');
        expect(wrapper.find('[data-recommendation-preview]').exists()).toBe(true);
        expect(wrapper.get('[data-recommendation-preview]').text()).toContain('Will be added');
        expect(fetch.mock.calls.every(([, options]) => options.method === 'GET')).toBe(true);
        for (const key of recommendedProfiles[code].permission_keys) { expect(wrapper.get(`input[value="${key}"]`).element.checked).toBe(false); }
        await button(wrapper, 'Use these selections').trigger('click');
        expect(current.element.checked).toBe(true);
        for (const key of recommendedProfiles[code].permission_keys) { expect(wrapper.get(`input[value="${key}"]`).element.checked).toBe(true); }
        expect(fetch.mock.calls.every(([, options]) => options.method === 'GET')).toBe(true);
        const removed = recommendedProfiles[code].permission_keys.at(-1);
        await wrapper.get(`input[value="${removed}"]`).setValue(false);
        await wrapper.get('form').trigger('submit'); await flushPromises();
        const updates = fetch.mock.calls.filter(([, options]) => options.method === 'PATCH');
        expect(updates).toHaveLength(1);
        expect(JSON.parse(updates[0][1].body).permission_keys).toEqual(['incoming.operations.reopen', ...recommendedProfiles[code].permission_keys.filter(key => key !== removed)]);
        expect(fetch.mock.calls.some(([url, options]) => url.startsWith(endpoints.assignments) && options.method !== 'GET')).toBe(false);
    });

    it('previews System Administrator recommendations while retaining own-role security protections', async () => {
        const fetch = server({ roles: [{ ...sampleRole, code: 'SECURITY_ADMINISTRATOR', security_editable: false }], catalog: recommendationCatalog, recommendations: recommendedProfiles });
        const wrapper = await render();
        await button(wrapper, 'Edit').trigger('click');
        await button(wrapper, 'Preview recommended permissions').trigger('click');
        expect(wrapper.get('[data-recommendation-preview]').text()).toContain('users.manage');
        expect(button(wrapper, 'Use these selections').element.disabled).toBe(true);
        expect(wrapper.get('input[value="users.manage"]').element.checked).toBe(false);
        expect(wrapper.text()).toContain('existing scope and self-edit protections');
        expect(fetch.mock.calls.every(([, options]) => options.method === 'GET')).toBe(true);
    });

    it('offers recommendations for a standard role during creation without assigning or saving automatically', async () => {
        const fetch = server({ catalog: recommendationCatalog, recommendations: recommendedProfiles });
        const wrapper = await render();
        await newRole(wrapper);
        await wrapper.get('input[name="role-code"]').setValue('VIEWER');
        await button(wrapper, 'Apply Recommended Permissions').trigger('click');
        await button(wrapper, 'Use these selections').trigger('click');
        expect(fetch.mock.calls.every(([, options]) => options.method === 'GET')).toBe(true);
        await wrapper.get('form').trigger('submit'); await flushPromises();
        const create = fetch.mock.calls.find(([url, options]) => url === endpoints.roles && options.method === 'POST');
        expect(JSON.parse(create[1].body)).toMatchObject({ code: 'VIEWER', permission_keys: recommendedProfiles.VIEWER.permission_keys, scope_type: 'global' });
    });

    it.each(['unavailable', 'not-delegatable'])('blocks applying recommendations containing a permission that is %s', async blocked => {
        const catalog = recommendationCatalog.map(permission => permission.key !== 'organization.access' ? permission : { ...permission, available: blocked !== 'unavailable', delegatable: blocked !== 'not-delegatable' });
        const fetch = server({ roles: [{ ...sampleRole, code: 'VIEWER', permission_keys: [] }], catalog, recommendations: recommendedProfiles });
        const wrapper = await render();
        await button(wrapper, 'Edit').trigger('click');
        await button(wrapper, 'Apply Recommended Permissions').trigger('click');
        expect(wrapper.get('[data-recommendation-preview]').text()).toContain('This recommendation cannot be applied');
        expect(button(wrapper, 'Use these selections').element.disabled).toBe(true);
        expect(wrapper.get('input[value="documents.metadata.view"]').element.checked).toBe(false);
        expect(fetch.mock.calls.every(([, options]) => options.method === 'GET')).toBe(true);
    });

    it('does not apply System Administrator recommendations outside global scope', async () => {
        const site = { ...globalScope, scope_key: 'site:10', scope_type: 'site', company_id: 7, site_id: 10, company: { name_en: 'Example Company' }, site: { name_en: 'Example Site' } };
        const fetch = server({ contexts: [site], roles: [{ ...sampleRole, scope_key: site.scope_key, code: 'SECURITY_ADMINISTRATOR', permission_keys: [] }], catalog: recommendationCatalog, recommendations: recommendedProfiles });
        const wrapper = await render();
        await button(wrapper, 'Edit').trigger('click');
        expect(wrapper.text()).toContain('available only for a Group / Global role definition');
        expect(button(wrapper, 'Preview recommended permissions').element.disabled).toBe(true);
        expect(wrapper.find('[data-recommendation-preview]').exists()).toBe(false);
        expect(fetch.mock.calls.every(([, options]) => options.method === 'GET')).toBe(true);
    });

    it('keeps shared-role recommendations read-only and translates their preview into Lao', async () => {
        const fetch = server({ roles: [{ ...sampleRole, code: 'DOCUMENT_CONTROLLER', editable: false }], catalog: recommendationCatalog, recommendations: recommendedProfiles });
        const wrapper = await render();
        await button(wrapper, 'Details').trigger('click');
        await button(wrapper, 'Preview recommended permissions').trigger('click');
        expect(button(wrapper, 'Use these selections').element.disabled).toBe(true);
        await wrapper.get('[data-language-switcher] input[value="lo"]').setValue(true);
        expect(wrapper.get('form').text()).toContain('ສິດທີ່ແນະນຳ');
        expect(wrapper.get('[data-recommendation-preview]').text()).toContain('ກວດສອບສິດທີ່ແນະນຳ');
        expect(wrapper.get('form').text()).not.toContain('rbacRecommendation');
        expect(fetch.mock.calls.every(([, options]) => options.method === 'GET')).toBe(true);
    });

    it('cancels a recommendation preview without changing existing selections', async () => {
        const fetch = server({ roles: [{ ...sampleRole, code: 'VIEWER', permission_keys: ['incoming.operations.reopen'] }], catalog: recommendationCatalog, recommendations: recommendedProfiles });
        const wrapper = await render();
        await button(wrapper, 'Edit').trigger('click');
        await button(wrapper, 'Apply Recommended Permissions').trigger('click');
        await button(wrapper.get('[data-recommendation-preview]'), 'Cancel').trigger('click');
        expect(wrapper.find('[data-recommendation-preview]').exists()).toBe(false);
        expect(wrapper.get('input[value="incoming.operations.reopen"]').element.checked).toBe(true);
        expect(wrapper.get('input[value="organization.access"]').element.checked).toBe(false);
        expect(fetch.mock.calls.every(([, options]) => options.method === 'GET')).toBe(true);
    });

    it('preserves ordinary custom-role editing without offering an unrelated standard recommendation', async () => {
        const fetch = server({ catalog: recommendationCatalog, recommendations: recommendedProfiles });
        const wrapper = await render();
        await button(wrapper, 'Edit').trigger('click');
        expect(wrapper.text()).not.toContain('Apply Recommended Permissions');
        await wrapper.get('input[value="incoming.operations.reopen"]').setValue(true);
        await wrapper.get('form').trigger('submit'); await flushPromises();
        const update = fetch.mock.calls.find(([, options]) => options.method === 'PATCH');
        expect(JSON.parse(update[1].body).permission_keys).toEqual(['roles.view', 'incoming.operations.reopen']);
    });

    it('uses one prominent scope selector and explains global, company and site scope in both languages', async () => {
        server();
        const wrapper = await render();
        const panel = wrapper.get('[data-administration-scope]');
        expect(panel.findAll('select')).toHaveLength(1);
        expect(panel.text()).toContain('Company / Site');
        expect(panel.text()).toContain('Current scope: Group / Global');
        expect(panel.text()).toContain('may affect users across companies and sites');
        expect(panel.get('dl').text()).toContain('owned by one company');
        expect(panel.get('dl').text()).toContain('owned by one site');
        expect(wrapper.get('[data-roles-scope]').text()).toContain('Roles & Permissions — current scope: Group / Global');
        await wrapper.get('[data-language-switcher] input[value="lo"]').setValue(true);
        expect(panel.text()).toContain('ບໍລິສັດ / ສະຖານທີ່');
        expect(panel.text()).toContain('ຂອບເຂດປັດຈຸບັນ');
        expect(panel.get('dl').text()).toContain('ບໍລິສັດ');
        expect(wrapper.get('[data-roles-scope]').text()).toContain('ບົດບາດ ແລະ ສິດ');
        expect(panel.text()).not.toContain('rbacScope');
    });

    it('switches a shared role to its authorized owning scope without changing the role or its assignments', async () => {
        const siteScope = { ...globalScope, scope_key: 'site:10', scope_type: 'site', company_id: 7, site_id: 10, company: { name_en: 'Example Company', name_lo: 'ຕົວຢ່າງ' }, site: { name_en: 'Example Site', name_lo: 'ສະຖານທີ່' } };
        const fetch = server({ contexts: [siteScope, globalScope], roles: [{ ...sampleRole, editable: false }] });
        const wrapper = await render();
        await button(wrapper, 'Details').trigger('click');
        expect(wrapper.get('[data-roles-scope]').text()).toContain('Example Site');
        await button(wrapper, 'Switch to Group / Global').trigger('click'); await flushPromises();
        expect(control(wrapper, 'Authorization scope').element.value).toBe('global');
        expect(wrapper.get('[data-roles-scope]').text()).toContain('Group / Global');
        expect(wrapper.find('input[name="role-code"]').exists()).toBe(false);
        expect(document.activeElement).toBe(control(wrapper, 'Authorization scope').element.nextElementSibling.querySelector('.select2-selection'));
        expect(fetch.mock.calls.some(([url]) => url.startsWith(endpoints.roles) && url.includes('scope_type=global'))).toBe(true);
        expect(fetch.mock.calls.every(([, options]) => options.method === 'GET')).toBe(true);
    });

    it.each([false, true])('offers no owner-switch editing action without owning-scope authority (view-only scope %s)', async viewOnly => {
        const siteScope = { ...globalScope, scope_key: 'site:10', scope_type: 'site', company_id: 7, site_id: 10, company: { name_en: 'Example Company', name_lo: 'ຕົວຢ່າງ' }, site: { name_en: 'Example Site', name_lo: 'ສະຖານທີ່' } };
        const contexts = viewOnly ? [siteScope, { ...globalScope, permissions: ['roles.view'] }] : [siteScope];
        const fetch = server({ contexts, roles: [{ ...sampleRole, editable: false }] });
        const wrapper = await render();
        await button(wrapper, 'Details').trigger('click');
        expect(wrapper.get('form').text()).toContain('You do not have role-editing access to this owning scope');
        expect(wrapper.findAll('button').some(item => item.text().startsWith('Switch to'))).toBe(false);
        expect(control(wrapper, 'Authorization scope').element.value).toBe('site:10');
        expect(fetch.mock.calls.every(([, options]) => options.method === 'GET')).toBe(true);
        await wrapper.get('[data-language-switcher] input[value="lo"]').setValue(true);
        expect(wrapper.get('form').text()).toContain('ທ່ານບໍ່ມີສິດແກ້ໄຂ');
    });

    it('groups business permissions and explains workspace setup without selecting or saving grants automatically', async () => {
        const catalog = [...permissionRows,
            { key: 'organization.access', area: 'organization', name_en: 'Use a workspace', name_lo: 'ພື້ນທີ່ເຮັດວຽກ', available: true, delegatable: true },
            { key: 'documents.metadata.view', area: 'document-security', name_en: 'View details', name_lo: 'ລາຍລະອຽດ', available: true, delegatable: true },
            { key: 'incoming.register', area: 'documents', name_en: 'Register incoming correspondence', name_lo: 'ລົງທະບຽນໜັງສືເຂົ້າ', available: true, delegatable: true },
            { key: 'controlled.register', area: 'documents', name_en: 'Register controlled documents', name_lo: 'ລົງທະບຽນເອກະສານຄວບຄຸມ', available: true, delegatable: true },
            { key: 'reports.view', area: 'reports', name_en: 'View reports', name_lo: 'ເບິ່ງລາຍງານ', available: true, delegatable: true },
            { key: 'numbering.issue', area: 'numbering', name_en: 'Issue numbers (planned)', name_lo: 'ອອກເລກ (ຢູ່ໃນແຜນ)', available: true, delegatable: true },
            { key: 'security.audit.view', area: 'document-security', name_en: 'View security events', name_lo: 'ເບິ່ງເຫດການຄວາມປອດໄພ', available: true, delegatable: true },
            { key: 'future.review', area: 'future', name_en: 'Future review capability (planned)', name_lo: 'ຄວາມສາມາດໃນອະນາຄົດ (ຢູ່ໃນແຜນ)', available: false, delegatable: false },
        ];
        const fetch = server({ roles: [{ ...sampleRole, code: 'DOCUMENT_CONTROLLER', name_en: 'Document Controller', permission_keys: [] }], catalog });
        const wrapper = await render();
        const list = wrapper.get('section[aria-labelledby="roles-heading"]');
        expect(list.text()).toContain('Document Controller');
        expect(list.text()).toContain('Authorization scope');
        expect(list.text()).toContain('Group / Global');
        expect(list.text()).toContain('Workspace setup needed');
        await button(wrapper, 'Edit').trigger('click');
        expect(wrapper.get('form [role="status"]').text()).toContain('cannot be selected during user creation');
        expect(wrapper.findAll('form input[value]').every(input => !input.element.checked)).toBe(true);
        const workspaceGroup = wrapper.get('[data-permission-group="workspace"]');
        expect(workspaceGroup.attributes('open')).toBeDefined();
        expect(workspaceGroup.text()).not.toContain('organization.access');
        expect(wrapper.get('[data-permission-group="correspondence"]').attributes('open')).toBeUndefined();
        expect(wrapper.get('[data-permission-group="controlled"]').text()).toContain('Controlled Documents');
        expect(wrapper.get('[data-permission-group="reports"]').text()).toContain('Search & Reports');
        expect(wrapper.get('[data-permission-group="security"]').text()).toContain('Security');
        expect(wrapper.get('[data-permission-group="document-tools"]').text()).not.toContain('planned');
        const advanced = wrapper.get('[data-permission-advanced]');
        expect(advanced.attributes('open')).toBeUndefined();
        expect(advanced.text()).toContain('organization.access');
        expect(advanced.get('input[value="documents.register"]').element.disabled).toBe(true);
        expect(advanced.text()).not.toContain('Register documents (planned)');
        expect(advanced.text()).toContain('Future review capability (planned)');
        expect(advanced.get('input[value="future.review"]').element.disabled).toBe(true);
        expect(fetch.mock.calls.every(([, options]) => options.method === 'GET')).toBe(true);
        await wrapper.get('input[value="organization.access"]').setValue(true);
        await wrapper.get('input[value="documents.metadata.view"]').setValue(true);
        expect(workspaceGroup.text()).toContain('2 of 2 selected');
        expect(wrapper.get('form [role="status"]').text()).toContain('Workspace permissions are configured');
        await wrapper.get('form').trigger('submit'); await flushPromises();
        const updates = fetch.mock.calls.filter(([, options]) => options.method === 'PATCH');
        expect(updates).toHaveLength(1);
        expect(JSON.parse(updates[0][1].body)).toMatchObject({ scope_type: 'global', permission_keys: ['organization.access', 'documents.metadata.view'] });
        expect(JSON.parse(updates[0][1].body)).not.toHaveProperty('code');
    });

    it('explains the owning scope of shared roles without enabling edits or moving ownership', async () => {
        const siteScope = { ...globalScope, scope_key: 'site:10', scope_type: 'site', company_id: 7, site_id: 10, company: { name_en: 'Example Company', name_lo: 'ຕົວຢ່າງ' }, site: { name_en: 'Example Site', name_lo: 'ສະຖານທີ່' } };
        const fetch = server({ contexts: [siteScope], roles: [{ ...sampleRole, editable: false }] });
        const wrapper = await render();
        await button(wrapper, 'Details').trigger('click');
        const editor = wrapper.get('[data-form-modal]');
        expect(editor.text()).toContain('Owned by: Group / Global');
        expect(editor.text()).toContain('Select that scope above');
        expect(editor.text()).not.toContain('Save changes');
        expect(editor.get('input[value="roles.view"]').element.disabled).toBe(true);
        expect(control(wrapper, 'Authorization scope').element.value).toBe('site:10');
        expect(fetch.mock.calls.every(([, options]) => options.method === 'GET')).toBe(true);
    });

    it('translates business groups, report headings and advanced details into Lao', async () => {
        server({ catalog: [...permissionRows, { key: 'reports.view', area: 'reports', name_en: 'View reports', name_lo: 'ເບິ່ງລາຍງານ', available: true, delegatable: true }] });
        const wrapper = await render();
        await button(wrapper, 'Edit').trigger('click');
        await wrapper.get('[data-language-switcher] input[value="lo"]').setValue(true);
        expect(wrapper.get('[data-permission-group="reports"]').text()).toContain('ຄົ້ນຫາ ແລະ ລາຍງານ');
        expect(wrapper.get('[data-permission-advanced] summary').text()).toContain('ຂັ້ນສູງ');
        expect(wrapper.get('form').text()).toContain('ບັນທຶກການປ່ຽນແປງ');
        expect(wrapper.text()).not.toContain('rbacArea-');
        expect(wrapper.text()).not.toContain('rbacGroup-');
    });

    it.each([
        [{ active: false }, 'inactive and cannot be selected'],
        [{ code: 'SECURITY_ADMINISTRATOR' }, 'excluded from simplified user onboarding'],
    ])('explains onboarding exclusions for protected or inactive roles (%j)', async (changes, explanation) => {
        server({ roles: [{ ...sampleRole, ...changes }] });
        const wrapper = await render();
        await button(wrapper, 'Edit').trigger('click');
        expect(wrapper.get('form [role="status"]').text()).toContain(explanation);
    });

    it('shows System Administrator in English and Lao for roles and assignments', async () => {
        const systemRole = { ...sampleRole, code: 'SECURITY_ADMINISTRATOR', name_en: 'System Administrator', name_lo: 'ຜູ້ບໍລິຫານລະບົບ' };
        server({ roles: [systemRole], assignments: [{ ...sampleAssignment, role: systemRole }] });
        const wrapper = await render();

        expect(wrapper.text()).toContain('System Administrator');
        expect(wrapper.text()).toContain('Confidential and Highly Confidential documents');
        await wrapper.get('[data-language-switcher] input[value="lo"]').setValue(true);
        expect(wrapper.text()).toContain('ຜູ້ບໍລິຫານລະບົບ');
    });

    it('excludes a protected global role from assignment choices when the server refuses delegation', async () => {
        const systemRole = { ...sampleRole, id: 11, code: 'SECURITY_ADMINISTRATOR', name_en: 'System Administrator', assignable: false };
        server({ roles: [sampleRole, systemRole] });
        const wrapper = await render();

        await wrapper.get('[data-open-assign]').trigger('click');
        await button(wrapper, 'Find eligible accounts and roles').trigger('click');
        await flushPromises();

        const choices = control(wrapper, 'Role').findAll('option').map(option => option.text());
        expect(choices).toContain('Example Role (EXAMPLE_ROLE)');
        expect(choices).not.toContain('System Administrator (SECURITY_ADMINISTRATOR)');
    });

    it('keeps company and site configuration panels out of the global scope even with full authority', async () => {
        server({ contexts: [{ ...globalScope, permissions: [...allKeys, 'master-data.view', 'master-data.manage', 'numbering.view', 'numbering.manage'] }] });
        const wrapper = await render();

        expect(wrapper.text()).not.toContain('Document master data');
        expect(wrapper.text()).not.toContain('Document numbering');
        expect(wrapper.text()).toContain('Roles');
    });

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
        await wrapper.get('[data-language-switcher] input[value="lo"]').setValue(true);

        expect(wrapper.text()).toContain('ບົດບາດ ແລະ ສິດ');
        expect(wrapper.text()).toContain('ບົດບາດຕົວຢ່າງ');
        expect(wrapper.text()).toContain('ເບິ່ງບົດບາດ');
        expect(localStorage.getItem('dcms.language')).toBe('lo');
    });

    it('shows concise inactive status labels for roles and assignments in both languages', async () => {
        server({ roles: [{ ...sampleRole, active: false }], assignments: [{ ...sampleAssignment, active: false }] });
        const wrapper = await render();

        expect(wrapper.findAll('td').filter(cell => cell.text() === 'Inactive')).toHaveLength(2);
        await wrapper.get('[data-language-switcher] input[value="lo"]').setValue(true);
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
        await settle();
        expect(swalText()).toContain('Role configuration saved.');
        expect(wrapper.text()).toContain('New Role');
    });

    it('focuses the role editor and directs keyboard users to the first invalid field without submitting', async () => {
        const fetch = server();
        const wrapper = await render();
        await button(wrapper, 'Create role').trigger('click');
        await flushPromises();
        const code = wrapper.get('input[name="role-code"]');
        expect(document.activeElement).toBe(code.element);

        button(wrapper, 'Save changes').element.focus();
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(document.activeElement).toBe(code.element);
        expect(code.attributes('aria-invalid')).toBe('true');
        expect(document.getElementById(code.attributes('aria-describedby').split(' ').at(-1)).textContent).toBe('This field is required.');
        expect(fetch.mock.calls.some(([, options]) => options.method === 'POST')).toBe(false);

        await code.setValue('EXAMPLE_NEW_ROLE');
        await wrapper.get('form').trigger('submit');
        await flushPromises();
        expect(document.activeElement).toBe(wrapper.get('input[name="role-name-en"]').element);
    });

    it('focuses a server-rejected role field and keeps its associated error bilingual', async () => {
        server({ mutationError: { status: 422, body: { errors: { name_lo: ['rbacValidation'] } } } });
        const wrapper = await render();
        await newRole(wrapper);
        button(wrapper, 'Save changes').element.focus();
        await wrapper.get('form').trigger('submit');
        await flushPromises();
        const field = wrapper.get('input[name="role-name-lo"]');

        expect(document.activeElement).toBe(field.element);
        expect(field.attributes('aria-invalid')).toBe('true');
        const errorId = field.attributes('aria-describedby');
        expect(document.getElementById(errorId).textContent).toBe('Check the supplied value and try again.');
        await wrapper.get('[data-language-switcher] input[value="lo"]').setValue(true);
        expect(document.getElementById(errorId).textContent).toBe('ກະລຸນາກວດສອບຄ່າທີ່ປ້ອນ ແລະ ລອງໃໝ່.');
    });

    it('opens inherited definitions read-only and locks security settings for the current users own role', async () => {
        server({ roles: [{ ...sampleRole, editable: false }] });
        const wrapper = await render();
        await button(wrapper, 'Details').trigger('click');
        await flushPromises();

        expect(document.activeElement).toBe(wrapper.get('[data-form-modal] [role="dialog"]').element);
        expect(wrapper.get('input[name="role-name-en"]').element.readOnly).toBe(true);
        expect(wrapper.get('input[value="roles.view"]').element.disabled).toBe(true);
        expect(wrapper.text()).not.toContain('Save changes');
        expect(wrapper.text()).toContain('This shared definition can be edited only');
    });

    it('locks permissions and active status on an assigned role while allowing display metadata edits', async () => {
        const fetch = server({ roles: [{ ...sampleRole, security_editable: false }] });
        const wrapper = await render();
        await button(wrapper, 'Edit').trigger('click');
        await flushPromises();

        expect(document.activeElement).toBe(wrapper.get('input[name="role-name-en"]').element);
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
        await wrapper.get('[data-open-assign]').trigger('click');
        await button(wrapper, 'Find eligible accounts and roles').trigger('click');
        await flushPromises();
        await control(wrapper, 'Account').setValue(2);
        await control(wrapper, 'Role').setValue(10);
        await button(wrapper.get('[data-form-modal]'), 'Assign role').trigger('click');
        await flushPromises();
        await settle();
        expect(swalText()).toContain('Group / Global');
        await settle();
        expect(swalText()).toContain('Example Account (example@example.test)');
        expect(swalText()).toContain('Example Role (EXAMPLE_ROLE)');
        expect(fetch.mock.calls.some(([, options]) => options.method === 'POST')).toBe(false);
        await swalConfirm();
        await flushPromises();

        const [, options] = fetch.mock.calls.find(([url, options]) => url === endpoints.assignments && options.method === 'POST');
        expect(JSON.parse(options.body)).toMatchObject({ user_id: 2, role_id: 10, scope_type: 'global', confirm_global: true });
        await settle();
        expect(swalText()).toContain('Role assignment updated.');
    });

    it('supports revocation confirmation and does not show a revoke control for protected rows', async () => {
        const fetch = server({ assignments: [sampleAssignment, { ...sampleAssignment, id: 21, revocable: false }] });
        const wrapper = await render();
        expect(wrapper.findAll('button').filter(item => item.text() === 'Revoke role')).toHaveLength(1);
        await button(wrapper, 'Revoke role').trigger('click');
        await flushPromises();
        await settle();
        expect(swalText()).toContain('Example Account (example@example.test)');
        expect(swalText()).toContain('Example Role (EXAMPLE_ROLE)');
        await swalCancel();
        await flushPromises();
        expect(fetch.mock.calls.some(([, options]) => options.method === 'DELETE')).toBe(false);
        await button(wrapper, 'Revoke role').trigger('click');
        await flushPromises();
        await swalConfirm();
        await flushPromises();

        expect(fetch.mock.calls.some(([url, options]) => url === `${endpoints.assignments}/20` && options.method === 'DELETE')).toBe(true);
        await settle();
        expect(swalText()).toContain('Role assignment updated.');
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
        await wrapper.get('[data-language-switcher] input[value="lo"]').setValue(true);
        expect(wrapper.get('[role="alert"]').text()).toContain('ຜູ້ບໍລິຫານ');
    });

    it('closes confirmation and exposes a revocation rejection instead of leaving the error behind a modal', async () => {
        server({ assignments: [sampleAssignment], mutationError: { status: 422, body: { errors: { authorization: ['rbacLastAdministrator'] } } } });
        const wrapper = await render();
        await button(wrapper, 'Revoke role').trigger('click');
        await flushPromises();
        await swalConfirm();
        await flushPromises();

        expect(swalPopup()).toBeNull();
        expect(wrapper.get('[role="alert"]').text()).toContain('final active security administrator');
    });

    it('clears account candidates and edits when the scope changes', async () => {
        const companyScope = { ...globalScope, scope_key: 'company:7', scope_type: 'company', company_id: 7, company: { name_en: 'Example Company', name_lo: 'ບໍລິສັດຕົວຢ່າງ' }, permissions: ['roles.view'] };
        const fetch = server({ contexts: [globalScope, companyScope] });
        const wrapper = await render();
        await wrapper.get('[data-open-assign]').trigger('click');
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
    it('selects only an authorized scope matching the shared workspace and follows workspace changes', async () => {
        const first = { ...globalScope, scope_key: 'site:10', scope_type: 'site', company_id: 7, site_id: 10, company: { name_en: 'Example Company', name_lo: 'ຕົວຢ່າງ' }, site: { name_en: 'First site', name_lo: 'ສະຖານທີ່' } };
        const second = { ...first, scope_key: 'site:11', site_id: 11 };
        const workspace = { selected: ref({ company_id: 7, site_id: 11 }) };
        server({ contexts: [globalScope, first, second] });
        const wrapper = await render(workspace);
        expect(control(wrapper, 'Authorization scope').element.value).toBe('site:11');
        workspace.selected.value = { company_id: 7, site_id: 10 };
        await flushPromises();
        expect(control(wrapper, 'Authorization scope').element.value).toBe('site:10');
        workspace.selected.value = { company_id: 999, site_id: 999 };
        await flushPromises();
        expect(control(wrapper, 'Authorization scope').element.value).toBe('site:10');
    });

    it('shows structural navigation only for confirmed System Administrator authority in global scope', async () => {
        server();
        const denied = await render();
        expect(denied.text()).not.toContain('Organization structure');
        denied.unmount();
        const companyScope = { ...globalScope, scope_key: 'company:7', scope_type: 'company', company_id: 7, company: { name_en: 'Example Company', name_lo: 'ຕົວຢ່າງ' } };
        server({ contexts: [globalScope, companyScope], canManageOrganization: true });
        const wrapper = await render();
        await wrapper.get('[data-admin-tab="organization"]').trigger('click');
        await flushPromises();
        expect(wrapper.text()).toContain('Global System Administrators maintain structure here.');
        await control(wrapper, 'Authorization scope').setValue('company:7');
        await flushPromises();
        expect(wrapper.text()).not.toContain('Organization structure');
    });

});
