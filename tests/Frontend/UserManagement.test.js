import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import UserManagement from '../../resources/js/components/UserManagement.vue';
import AdministrationPage from '../../resources/js/pages/AdministrationPage.vue';
import { createLocalization } from '../../resources/js/localization/index.js';

enableAutoUnmount(afterEach);
beforeEach(() => localStorage.clear());
afterEach(() => vi.unstubAllGlobals());

function server(initialUsers = []) {
    const users = structuredClone(initialUsers);
    const fetch = vi.fn(async (url, options = {}) => {
        const method = options.method ?? 'GET';
        if (method === 'POST') {
            const body = JSON.parse(options.body);
            users.push({ id: 2, name: body.name, email: body.email, active: true, preferred_language: body.preferred_language });
            return { ok: true, status: 201, json: async () => ({ data: users[0] }) };
        }
        if (method === 'GET') { return { ok: true, status: 200, json: async () => ({ data: users, last_page: 1 }) }; }
        if (method === 'PATCH') {
            const body = JSON.parse(options.body);
            Object.assign(users.find(user => url.endsWith(`/${user.id}`)), body);
            return { ok: true, status: 200, json: async () => ({ data: users[0] }) };
        }
        throw new Error(`Unexpected request ${method} ${url}`);
    });
    vi.stubGlobal('fetch', fetch);
    return fetch;
}

describe('simple account administration', () => {
    it('creates an identity without automatically selecting a role or membership', async () => {
        const fetch = server();
        const wrapper = mount(UserManagement, { props: { endpoint: '/api/v1/user-management', csrfToken: 'csrf-token', currentUserId: 1 }, global: { plugins: [createLocalization()] } });
        await flushPromises();
        const create = wrapper.findAll('button').find(button => button.text() === 'Create user');
        await create.trigger('click');
        const inputs = wrapper.findAll('input');
        await inputs.find(input => input.attributes('type') === 'text' && input.attributes('required') !== undefined).setValue('XPPL Employee');
        await inputs.find(input => input.attributes('type') === 'email').setValue('employee@example.test');
        const passwords = wrapper.findAll('input[type="password"]');
        await passwords[0].setValue('SecurePass123!');
        await passwords[1].setValue('SecurePass123!');
        await wrapper.findAll('form')[1].trigger('submit');
        await flushPromises();
        expect(wrapper.text()).toContain('employee@example.test');
        const post = fetch.mock.calls.find(([, options]) => options.method === 'POST');
        expect(post[1].headers['X-CSRF-TOKEN']).toBe('csrf-token');
        expect(JSON.parse(post[1].body)).not.toHaveProperty('role_id');
        expect(JSON.parse(post[1].body)).not.toHaveProperty('membership_id');
    });

    it('edits identity and reactivates an account without changing its password', async () => {
        const fetch = server([{ id: 2, name: 'Example User', email: 'old@example.test', active: false, preferred_language: 'en' }]);
        const wrapper = mount(UserManagement, { props: { endpoint: '/api/v1/user-management', csrfToken: 'csrf-token', currentUserId: 1 }, global: { plugins: [createLocalization()] } });
        await flushPromises();
        await wrapper.findAll('button').find(button => button.text() === 'Edit').trigger('click');
        const editor = wrapper.findAll('form')[1];
        await editor.find('input[type="text"]').setValue('Renamed User');
        await editor.find('input[type="email"]').setValue('new@example.test');
        await editor.find('input[type="checkbox"]').setValue(true);
        await editor.trigger('submit');
        await flushPromises();
        const patch = fetch.mock.calls.find(([, options]) => options.method === 'PATCH');
        expect(patch[0]).toBe('/api/v1/user-management/2');
        expect(JSON.parse(patch[1].body)).toEqual({ name: 'Renamed User', email: 'new@example.test', preferred_language: 'en', active: true });
        expect(wrapper.text()).toContain('Renamed User');
        expect(wrapper.text()).toContain('Active');
    });

    it('searches accounts and hides its own activation control', async () => {
        const fetch = server([{ id: 1, name: 'Administrator', email: 'admin@example.test', active: true, preferred_language: 'en' }]);
        const wrapper = mount(UserManagement, { props: { endpoint: '/api/v1/user-management', csrfToken: 'csrf-token', currentUserId: 1 }, global: { plugins: [createLocalization()] } });
        await flushPromises();
        await wrapper.find('input').setValue('admin@example.test');
        await wrapper.find('form').trigger('submit');
        await flushPromises();
        expect(fetch.mock.calls.at(-1)[0]).toContain('q=admin%40example.test');
        await wrapper.findAll('button').find(button => button.text() === 'Edit').trigger('click');
        expect(wrapper.find('input[type="checkbox"]').exists()).toBe(false);
    });

    it('integrates the reused Users section with Lao Administration labels', async () => {
        vi.stubGlobal('fetch', vi.fn(async url => ({ ok: true, status: 200, json: async () => url === '/contexts'
            ? { data: [{ scope_key: 'global', scope_type: 'global', company_id: null, site_id: null, permissions: ['users.manage'] }] }
            : { data: [], last_page: 1 } })));
        const wrapper = mount(AdministrationPage, { props: { endpoints: { contexts: '/contexts', userManagement: '/api/v1/user-management', currentUserId: 1 }, csrfToken: 'csrf-token' }, global: { plugins: [createLocalization('lo')], stubs: { ConfirmDialog: true } } });
        await flushPromises();
        expect(wrapper.findComponent(UserManagement).exists()).toBe(true);
        expect(wrapper.text()).toContain('ສ້າງຜູ້ໃຊ້');
        expect(wrapper.text()).not.toContain('userManagementTitle');
    });
});
