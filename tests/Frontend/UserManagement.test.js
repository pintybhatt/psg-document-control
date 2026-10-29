import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import UserManagement from '../../resources/js/components/UserManagement.vue';
import { createLocalization } from '../../resources/js/localization/index.js';

enableAutoUnmount(afterEach);
beforeEach(() => localStorage.clear());
afterEach(() => vi.unstubAllGlobals());

function server() {
    const users = [];
    const fetch = vi.fn(async (url, options = {}) => {
        const method = options.method ?? 'GET';
        if (method === 'POST') {
            const body = JSON.parse(options.body);
            users.push({ id: 2, name: body.name, email: body.email, active: true, preferred_language: body.preferred_language });
            return { ok: true, status: 201, json: async () => ({ data: users[0] }) };
        }
        if (method === 'GET') { return { ok: true, status: 200, json: async () => ({ data: users, last_page: 1 }) }; }
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
});
