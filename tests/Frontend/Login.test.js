import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { enableAutoUnmount, mount } from '@vue/test-utils';
import App from '../../resources/js/App.vue';
import LoginPage from '../../resources/js/pages/LoginPage.vue';
import LogoutButton from '../../resources/js/components/LogoutButton.vue';
import { createLocalization } from '../../resources/js/localization/index.js';

enableAutoUnmount(afterEach);
afterEach(() => document.body.replaceChildren());
beforeEach(() => localStorage.clear());

const auth = { loginUrl: '/login', logoutUrl: '/logout', csrfToken: 'sample-csrf-token', email: '', errors: {} };

function renderLogin(values = {}, initialLanguage = 'en') {
    return mount(LoginPage, {
        attachTo: document.body,
        props: { auth: { ...auth, ...values } },
        global: { plugins: [createLocalization(initialLanguage)] },
    });
}

describe('bilingual browser login', () => {
    it('uses a native CSRF-protected password form with appropriate labels and autocomplete', () => {
        const wrapper = renderLogin();

        expect(wrapper.get('form').attributes('method')).toBe('post');
        expect(wrapper.get('form').attributes('action')).toBe('/login');
        expect(wrapper.get('input[name="_token"]').element.value).toBe('sample-csrf-token');
        expect(wrapper.get('input[name="email"]').attributes('autocomplete')).toBe('username');
        expect(wrapper.get('input[name="password"]').attributes('type')).toBe('password');
        expect(wrapper.get('input[name="password"]').attributes('autocomplete')).toBe('current-password');
        for (const input of wrapper.findAll('input[required]')) {
            expect(wrapper.get(`label[for="${input.attributes('id')}"]`).text()).not.toBe('');
        }
        expect(wrapper.findAll('a')).toHaveLength(0);
    });

    it('prevents empty submissions, focuses the first invalid field and translates validation live', async () => {
        const wrapper = renderLogin();
        const event = new Event('submit', { bubbles: true, cancelable: true });

        wrapper.get('form').element.dispatchEvent(event);
        await wrapper.vm.$nextTick();

        expect(event.defaultPrevented).toBe(true);
        expect(wrapper.findAll('[role="alert"]').map(error => error.text())).toEqual(['Enter your email address.', 'Enter your password.']);
        expect(document.activeElement).toBe(wrapper.get('input[name="email"]').element);
        await wrapper.get('select').setValue('lo');
        expect(wrapper.get('[role="alert"]').text()).toBe('ກະລຸນາປ້ອນທີ່ຢູ່ອີເມວ.');
        expect(wrapper.get('input[name="language"]').element.value).toBe('lo');
        expect(wrapper.get('button[type="submit"]').text()).toBe('ເຂົ້າສູ່ລະບົບ');
    });

    it('rejects malformed email input and allows a valid native submission without storing the password', async () => {
        const wrapper = renderLogin();
        await wrapper.get('input[name="email"]').setValue('invalid');
        await wrapper.get('input[name="password"]').setValue('Test-only secret 123');
        await wrapper.get('form').trigger('submit');

        expect(wrapper.get('[role="alert"]').text()).toBe('Enter a valid email address.');
        await wrapper.get('input[name="email"]').setValue('example@example.test');
        const event = new Event('submit', { bubbles: true, cancelable: true });
        wrapper.get('form').element.dispatchEvent(event);
        await wrapper.vm.$nextTick();

        expect(event.defaultPrevented).toBe(false);
        expect(wrapper.findAll('[role="alert"]')).toHaveLength(0);
        expect(wrapper.get('button[type="submit"]').attributes('aria-busy')).toBe('true');
        expect(wrapper.get('button[type="submit"]').element.disabled).toBe(true);
        expect(Object.values(localStorage)).not.toContain('Test-only secret 123');
    });

    it.each(['authInvalidCredentials', 'authThrottled'])('translates the server error %s without prepopulating a password', async (errorKey) => {
        const wrapper = renderLogin({ email: 'example@example.test', errors: { email: errorKey } });
        expect(wrapper.get('input[name="email"]').element.value).toBe('example@example.test');
        expect(wrapper.get('input[name="password"]').element.value).toBe('');
        const englishError = wrapper.get('[role="alert"]').text();

        await wrapper.get('select').setValue('lo');

        expect(wrapper.get('[role="alert"]').text()).not.toBe(englishError);
        expect(wrapper.get('[role="alert"]').text()).not.toBe(errorKey);
    });

    it('preserves Lao through remounts and passes it through the logout form', async () => {
        const login = renderLogin();
        await login.get('select').setValue('lo');
        login.unmount();

        const logout = mount(LogoutButton, {
            props: { auth }, global: { plugins: [createLocalization()] },
        });
        expect(logout.get('form').attributes('method')).toBe('post');
        expect(logout.get('form').attributes('action')).toBe('/logout');
        expect(logout.get('input[name="_token"]').element.value).toBe('sample-csrf-token');
        expect(logout.get('input[name="language"]').element.value).toBe('lo');
        expect(logout.get('button').text()).toBe('ອອກຈາກລະບົບ');
        logout.unmount();

        const refreshed = renderLogin();
        expect(refreshed.get('select').element.value).toBe('lo');
        expect(localStorage.getItem('dcms.language')).toBe('lo');
    });

    it('uses the server language when browser storage is unavailable', () => {
        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('Storage blocked'); });
        const wrapper = renderLogin({}, 'lo');

        expect(wrapper.get('select').element.value).toBe('lo');
        expect(document.documentElement.lang).toBe('lo');
    });

    it('mounts the login page through the shared application without workspace navigation', () => {
        const wrapper = mount(App, {
            props: { page: 'login', navigation: [], context: {}, auth },
            global: { plugins: [createLocalization()] },
        });

        expect(wrapper.find('aside').exists()).toBe(false);
        expect(wrapper.get('form').attributes('action')).toBe('/login');
        expect(document.title).toBe('Sign in · DCMS');
    });
});
