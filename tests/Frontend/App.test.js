import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import App from '../../resources/js/App.vue';
import { createLocalization } from '../../resources/js/localization/index.js';
import en from '../../resources/js/localization/en.js';
import lo from '../../resources/js/localization/lo.js';

const glightbox = vi.hoisted(() => {
    const open = vi.fn();
    return { open, factory: vi.fn(() => ({ open, destroy: vi.fn() })) };
});
vi.mock('glightbox', () => ({ default: glightbox.factory }));

enableAutoUnmount(afterEach);

afterEach(() => {
    document.body.replaceChildren();
});

const navigation = [
    { key: 'dashboard', href: '/dashboard' },
    { key: 'incoming', href: '/incoming' },
    { key: 'outgoing', href: '/outgoing' },
    { key: 'controlled-documents', href: '/controlled-documents' },
    { key: 'document-search', href: '/document-search' },
    { key: 'reports', href: '/reports' },
    { key: 'administration', href: '/administration' },
];

function mountWorkspace(page = 'dashboard', context = { company: { id: null, name: 'Phonesack Group' }, site: null }) {
    return mount(App, {
        attachTo: document.body,
        props: { page, navigation, context },
        global: { plugins: [createLocalization()] },
    });
}

beforeEach(() => {
    localStorage.clear();
    document.documentElement.lang = 'en';
});

describe('application shell', () => {
    it('omits Administration from desktop and mobile navigation when the server supplies only operational destinations', async () => {
        const operationalNavigation = navigation.filter(item => item.key !== 'administration');
        const wrapper = mount(App, {
            props: { page: 'dashboard', navigation: operationalNavigation, context: { company: { name: 'Example Company' }, site: null } },
            global: { plugins: [createLocalization()] },
        });
        const expected = ['/dashboard', '/incoming', '/outgoing', '/controlled-documents', '/document-search', '/reports'];
        expect(wrapper.get('aside nav').findAll('a').map(link => link.attributes('href'))).toEqual(expected);
        await wrapper.get('button[aria-controls="mobile-navigation"]').trigger('click');
        expect(wrapper.get('#mobile-navigation').findAll('a').map(link => link.attributes('href'))).toEqual(expected);
        await wrapper.get('[data-language-switcher] input[value="lo"]').setValue(true);
        expect(wrapper.get('aside nav').text()).toContain('ເອກະສານຂາເຂົ້າ');
        expect(wrapper.find('a[href="/administration"]').exists()).toBe(false);
    });

    it('renders the dashboard with seven navigation destinations and an active page', () => {
        const wrapper = mountWorkspace();
        const links = wrapper.get('aside nav').findAll('a');

        expect(wrapper.get('h1').text()).toBe('Dashboard');
        expect(links.map(link => link.text())).toEqual([
            'Dashboard', 'Incoming', 'Outgoing', 'Controlled Documents', 'Document Search', 'Reports', 'Administration',
        ]);
        expect(links.map(link => link.attributes('href'))).toEqual([
            '/dashboard', '/incoming', '/outgoing', '/controlled-documents', '/document-search', '/reports', '/administration',
        ]);
        expect(wrapper.get('aside [aria-current="page"]').attributes('href')).toBe('/dashboard');
        expect(wrapper.get('main').text()).toContain('No site selected');
        expect(wrapper.get('main').text()).toContain(en.workspaceNoContexts);
        expect(wrapper.get('main').findAll('a')).toHaveLength(0);
    });

    it.each([
        ['incoming', 'Incoming'],
        ['outgoing', 'Outgoing'],
        ['controlled-documents', 'Controlled Documents'],
        ['document-search', 'Document Search'],
        ['reports', 'Reports'],
        ['administration', 'Administration'],
    ])('renders the %s placeholder without enabling document workflows', (page, heading) => {
        const wrapper = mountWorkspace(page);

        expect(wrapper.get('h1').text()).toBe(heading);
        expect(wrapper.get('main').text()).toContain('This module is a preview.');
        expect(wrapper.get('main a').attributes('href')).toBe('/dashboard');
        expect(wrapper.get('aside [aria-current="page"]').attributes('href')).toBe(`/${page}`);
        expect(wrapper.find('form').exists()).toBe(false);
    });

    it('opens and closes mobile navigation, including Escape', async () => {
        const wrapper = mountWorkspace();
        const button = wrapper.get('button[aria-controls="mobile-navigation"]');
        const menu = wrapper.get('#mobile-navigation');

        expect(button.attributes('aria-expanded')).toBe('false');
        expect(menu.isVisible()).toBe(false);

        await button.trigger('click');

        expect(button.attributes('aria-expanded')).toBe('true');
        expect(menu.isVisible()).toBe(true);
        expect(menu.findAll('a')).toHaveLength(7);

        await menu.trigger('keydown', { key: 'Escape' });

        expect(button.attributes('aria-expanded')).toBe('false');
        expect(menu.isVisible()).toBe(false);
        await button.trigger('click');
        await button.trigger('keydown', { key: 'Escape' });
        expect(button.attributes('aria-expanded')).toBe('false');
    });

    it('collapses the desktop sidebar to icons, keeps accessible labels and remembers the choice', async () => {
        const wrapper = mountWorkspace();
        const toggle = wrapper.get('button[aria-controls="desktop-navigation"]');

        expect(toggle.attributes('aria-expanded')).toBe('true');
        expect(toggle.attributes('aria-label')).toBe('Collapse menu');
        expect(wrapper.get('aside').classes()).toContain('w-64');

        await toggle.trigger('click');

        expect(toggle.attributes('aria-expanded')).toBe('false');
        expect(toggle.attributes('aria-label')).toBe('Expand menu');
        expect(wrapper.get('aside').classes()).toContain('w-20');
        expect(wrapper.get('aside a[href="/incoming"]').attributes('title')).toBe('Incoming');
        expect(wrapper.get('aside nav').findAll('a').map(link => link.text())).toContain('Incoming');
        expect(localStorage.getItem('dcms.sidebarCollapsed')).toBe('1');

        wrapper.unmount();
        const remounted = mountWorkspace();
        expect(remounted.get('aside').classes()).toContain('w-20');
        await remounted.get('[data-language-switcher] input[value="lo"]').setValue(true);
        expect(remounted.get('button[aria-controls="desktop-navigation"]').attributes('aria-label')).toBe('ຂະຫຍາຍເມນູ');
    });

    it('shows the signed-in profile with initials and a sign-out menu, and a flag for the selected language', async () => {
        const auth = { userId: 3, user: { name: 'Example Person', email: 'person@example.test' }, logoutUrl: '/logout', csrfToken: 'csrf' };
        const wrapper = mount(App, { attachTo: document.body, props: { page: 'dashboard', navigation, context: { company: { id: null, name: 'Phonesack Group' }, site: null }, auth }, global: { plugins: [createLocalization()] } });
        const trigger = wrapper.get('[data-user-menu-trigger]');

        expect(trigger.get('[data-avatar]').text()).toBe('EP');
        expect(trigger.text()).toContain('Example Person');
        expect(trigger.text()).toContain('person@example.test');
        expect(wrapper.get('#user-menu').isVisible()).toBe(false);

        await trigger.trigger('click');
        expect(trigger.attributes('aria-expanded')).toBe('true');
        expect(wrapper.get('#user-menu').isVisible()).toBe(true);
        expect(wrapper.get('#user-menu form').attributes('action')).toBe('/logout');
        expect(wrapper.get('#user-menu button[type="submit"]').text()).toBe('Sign out');

        await wrapper.get('#user-menu').trigger('keydown', { key: 'Escape' });
        expect(wrapper.get('#user-menu').isVisible()).toBe(false);

        const knob = () => wrapper.get('header [data-language-knob]').classes();
        await wrapper.get('header [data-language-switcher] input[value="lo"]').setValue(true);
        expect(knob()).toContain('translate-x-full');
        expect(wrapper.get('#user-menu button[type="submit"]').text()).toBe('ອອກຈາກລະບົບ');
    });

    it('links the account menu user details to the profile page', async () => {
        const auth = { userId: 3, user: { name: 'Example Person', email: 'person@example.test' }, profileUrl: '/profile', logoutUrl: '/logout', csrfToken: 'csrf' };
        const wrapper = mount(App, { attachTo: document.body, props: { page: 'dashboard', navigation, context: { company: { id: null, name: 'Phonesack Group' }, site: null }, auth }, global: { plugins: [createLocalization()] } });
        await wrapper.get('[data-user-menu-trigger]').trigger('click');
        const link = wrapper.get('#user-menu [data-user-profile-link]');

        expect(link.element.tagName).toBe('A');
        expect(link.attributes('href')).toBe('/profile');
        expect(link.text()).toContain('Example Person');
        expect(link.text()).toContain('View profile');
        expect(document.activeElement).toBe(link.element);
        wrapper.unmount();
    });

    it('shows the signed-in user roles, memberships, and granted permissions on the profile page', async () => {
        const company = { name_en: 'Example Company', name_lo: 'ບໍລິສັດຕົວຢ່າງ' };
        const site = { name_en: 'Example Site', name_lo: 'ສະຖານທີ່ຕົວຢ່າງ' };
        const profile = {
            system_administrator: false,
            roles: [{ code: 'VIEWER', name_en: 'Viewer', name_lo: 'ຜູ້ເບິ່ງ', scope: { type: 'site', company, site } }],
            memberships: [{ type: 'department', company, site, department: { name_en: 'Finance', name_lo: 'ການເງິນ' } }],
            permissions: [
                { key: 'reports.view', area: 'reports', name_en: 'View and export operational reports', name_lo: 'ເບິ່ງ ແລະ ສົ່ງອອກລາຍງານການດຳເນີນງານ' },
                { key: 'documents.metadata.view', area: 'document-security', name_en: 'Discover and view document metadata', name_lo: 'ຄົ້ນຫາ ແລະ ເບິ່ງຂໍ້ມູນເອກະສານ' },
            ],
        };
        const auth = { userId: 3, user: { name: 'Example Person', email: 'person@example.test' }, profileUrl: '/profile', logoutUrl: '/logout', csrfToken: 'csrf' };
        const wrapper = mount(App, { attachTo: document.body, props: { page: 'profile', navigation, context: { company: { id: null, name: 'Phonesack Group' }, site: null }, auth, profile }, global: { plugins: [createLocalization()] } });
        await flushPromises();
        await vi.dynamicImportSettled();
        await flushPromises();
        const panel = wrapper.get('[data-profile-page]');

        expect(wrapper.get('h1').text()).toBe('My Profile');
        expect(panel.get('[data-profile-account]').text()).toContain('person@example.test');
        expect(panel.find('[data-profile-system-administrator]').exists()).toBe(false);
        expect(panel.get('[data-profile-role]').text()).toContain('Viewer');
        expect(panel.get('[data-profile-role]').text()).toContain('Example Company / Example Site');
        expect(panel.get('[data-profile-membership]').text()).toContain('Department');
        expect(panel.get('[data-profile-membership]').text()).toContain('Example Company / Example Site / Finance');
        expect(panel.get('[data-profile-permissions] h3').text()).toBe('Granted permissions (2)');
        expect(panel.get('[data-profile-stat="roles"]').text()).toContain('1');
        expect(panel.get('[data-profile-stat="permissions"]').text()).toContain('2');
        expect(panel.findAll('[data-profile-permission-group]')).toHaveLength(2);
        expect(panel.get('[data-profile-permissions]').text()).toContain('View and export operational reports');

        await wrapper.get('header [data-language-switcher] input[value="lo"]').setValue(true);
        expect(panel.get('[data-profile-role]').text()).toContain('ຜູ້ເບິ່ງ');
        expect(panel.get('[data-profile-membership]').text()).toContain('ການເງິນ');
        wrapper.unmount();
    });

    it('changes the password from the profile page and shows server validation in place', async () => {
        const profile = { system_administrator: false, roles: [], memberships: [], permissions: [] };
        const auth = { userId: 3, user: { name: 'Example Person', email: 'person@example.test' }, profileUrl: '/profile', profilePasswordEndpoint: '/api/v1/profile/password', logoutUrl: '/logout', csrfToken: 'csrf' };
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({ errors: { current_password: ['profilePasswordCurrentInvalid'] } }), { status: 422, headers: { 'Content-Type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({ message: 'profilePasswordUpdated' }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(App, { attachTo: document.body, props: { page: 'profile', navigation, context: { company: { id: null, name: 'Phonesack Group' }, site: null }, auth, profile }, global: { plugins: [createLocalization()] } });
        await flushPromises();
        await vi.dynamicImportSettled();
        await flushPromises();
        const card = wrapper.get('[data-profile-security]');
        const submit = card.get('[data-password-submit]');

        expect(card.findAll('input[type="password"]')).toHaveLength(3);
        expect(submit.element.disabled).toBe(true);
        await card.get('input[name="current_password"]').setValue('wrong-password');
        await card.get('input[name="password"]').setValue('New-password-2');
        expect(card.get('[data-password-strength]').attributes('data-level')).toBe('strong');
        expect(submit.element.disabled).toBe(true);
        await card.get('input[name="password_confirmation"]').setValue('New-password-2');
        expect(card.findAll('[data-password-rule][data-met="true"]')).toHaveLength(3);
        expect(submit.element.disabled).toBe(false);

        await card.get('[data-password-form]').trigger('submit');
        await flushPromises();
        const [url, options] = fetchMock.mock.calls[0];
        expect(url).toBe('/api/v1/profile/password');
        expect(options.method).toBe('PUT');
        expect(JSON.parse(options.body)).toEqual({ current_password: 'wrong-password', password: 'New-password-2', password_confirmation: 'New-password-2' });
        expect(card.text()).toContain('The current password is incorrect.');

        await card.get('input[name="current_password"]').setValue('old-password-1');
        await card.get('[data-password-form]').trigger('submit');
        await flushPromises();
        expect(card.text()).not.toContain('The current password is incorrect.');
        expect(card.get('input[name="current_password"]').element.value).toBe('');
        expect(card.get('input[name="password"]').element.value).toBe('');

        await card.get('input[type="checkbox"]').setValue(true);
        expect(card.findAll('input[type="text"][name]')).toHaveLength(3);
        vi.unstubAllGlobals();
    });

    it('uploads a dropped profile photo, updates the header avatar, and opens it in the lightbox', async () => {
        const profile = { system_administrator: true, roles: [], memberships: [], permissions: [] };
        const auth = { userId: 3, user: { name: 'Example Person', email: 'person@example.test', photoUrl: null }, profileUrl: '/profile', profilePhotoEndpoint: '/api/v1/profile/photo', logoutUrl: '/logout', csrfToken: 'csrf' };
        const fetchMock = vi.fn(async () => new Response(JSON.stringify({ photoUrl: '/profile/photo?v=abc' }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);
        URL.createObjectURL = vi.fn(() => 'blob:preview');
        URL.revokeObjectURL = vi.fn();
        const wrapper = mount(App, { attachTo: document.body, props: { page: 'profile', navigation, context: { company: { id: null, name: 'Phonesack Group' }, site: null }, auth, profile }, global: { plugins: [createLocalization()] } });
        await flushPromises();
        await vi.dynamicImportSettled();
        await flushPromises();
        const drop = wrapper.get('[data-profile-photo-drop]');

        await drop.trigger('drop', { dataTransfer: { files: [new File(['text'], 'notes.txt', { type: 'text/plain' })] } });
        expect(wrapper.get('[data-profile-photo-error]').text()).toBe('Choose a JPG, PNG or WebP image.');
        expect(fetchMock).not.toHaveBeenCalled();

        await drop.trigger('drop', { dataTransfer: { files: [new File([new Uint8Array(10)], 'me.png', { type: 'image/png' })] } });
        await flushPromises();
        const [url, options] = fetchMock.mock.calls[0];
        expect(url).toBe('/api/v1/profile/photo');
        expect(options.method).toBe('POST');
        expect(options.body.get('photo').name).toBe('me.png');
        expect(wrapper.find('[data-profile-photo-error]').exists()).toBe(false);
        expect(wrapper.get('[data-user-menu-trigger] img').attributes('src')).toBe('/profile/photo?v=abc');
        expect(wrapper.find('[data-profile-photo-remove]').exists()).toBe(true);

        await wrapper.get('[data-profile-photo-open]').trigger('click');
        await flushPromises();
        await vi.dynamicImportSettled();
        expect(glightbox.factory).toHaveBeenCalledWith(expect.objectContaining({ elements: [expect.objectContaining({ href: '/profile/photo?v=abc', type: 'image' })] }));
        expect(glightbox.open).toHaveBeenCalled();
        vi.unstubAllGlobals();
    });

    it('does not treat unvalidated shell labels as an authorized workspace', () => {
        const wrapper = mountWorkspace('dashboard', {
            company: { id: 'company-example', name: 'Example Company' },
            site: { id: 'site-example', name: 'Example Site' },
        });

        expect(wrapper.get('header').text()).toContain('Example Company');
        expect(wrapper.get('header').text()).toContain('No site selected');
        expect(wrapper.get('main').text()).toContain(en.workspaceNoContexts);
    });
});

describe('localization', () => {
    it('switches the entire shell and page to Lao and restores it on navigation and refresh', async () => {
        const dashboard = mountWorkspace();

        await dashboard.get('[data-language-switcher] input[value="lo"]').setValue(true);

        expect(dashboard.get('h1').text()).toBe('ພາບລວມ');
        expect(dashboard.get('aside nav a').text()).toBe('ພາບລວມ');
        expect(dashboard.get('main').text()).toContain(lo.documentOverview);
        expect(dashboard.get('[data-language-switcher]').attributes('aria-label')).toBe('ພາສາ');
        expect(document.documentElement.lang).toBe('lo');
        expect(document.title).toBe('ພາບລວມ · DCMS');
        expect(localStorage.getItem('dcms.language')).toBe('lo');
        dashboard.unmount();

        const incoming = mountWorkspace('incoming');

        expect(incoming.get('h1').text()).toBe('ເອກະສານຂາເຂົ້າ');
        expect(incoming.get('[data-language-switcher] input:checked').element.value).toBe('lo');
        incoming.unmount();

        const refreshed = mountWorkspace('incoming');

        expect(refreshed.get('h1').text()).toBe('ເອກະສານຂາເຂົ້າ');
        await refreshed.get('[data-language-switcher] input[value="en"]').setValue(true);

        expect(refreshed.get('h1').text()).toBe('Incoming');
        expect(document.documentElement.lang).toBe('en');
        expect(document.title).toBe('Incoming · DCMS');
        expect(localStorage.getItem('dcms.language')).toBe('en');
    });

    it.each(['unsupported', '__proto__', 'constructor'])('falls back to English for invalid stored language %s', (value) => {
        localStorage.setItem('dcms.language', value);

        const wrapper = mountWorkspace();

        expect(wrapper.get('h1').text()).toBe('Dashboard');
        expect(wrapper.get('[data-language-switcher] input:checked').element.value).toBe('en');
        expect(document.documentElement.lang).toBe('en');
    });

    it('keeps the application and switcher usable when browser storage is blocked', async () => {
        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('Storage blocked'); });
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('Storage blocked'); });

        const wrapper = mountWorkspace();
        await wrapper.get('[data-language-switcher] input[value="lo"]').setValue(true);

        expect(wrapper.get('h1').text()).toBe('ພາບລວມ');
        expect(document.documentElement.lang).toBe('lo');
    });

    it('provides a non-empty Lao translation for every English dictionary entry', () => {
        expect(Object.keys(lo).sort()).toEqual(Object.keys(en).sort());
        expect(Object.values(lo).every(value => typeof value === 'string' && value.trim().length > 0)).toBe(true);
    });
});

describe('Vite entry point', () => {
    it('mounts Vue using the server-provided props', async () => {
        const element = document.createElement('div');
        element.id = 'dcms-app';
        element.dataset.props = JSON.stringify({
            page: 'reports', navigation, context: { company: { id: null, name: 'Phonesack Group' }, site: null },
        });
        document.body.append(element);

        const { application } = await import('../../resources/js/app.js');
        await nextTick();

        expect(element.getAttribute('data-v-app')).toBe('');
        expect(element.querySelector('h1').textContent).toBe('Reports');

        application.unmount();
        element.remove();
    });
});
