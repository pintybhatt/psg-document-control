import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { enableAutoUnmount, mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import App from '../../resources/js/App.vue';
import { createLocalization } from '../../resources/js/localization/index.js';
import en from '../../resources/js/localization/en.js';
import lo from '../../resources/js/localization/lo.js';

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

        await dashboard.get('select').setValue('lo');

        expect(dashboard.get('h1').text()).toBe('ພາບລວມ');
        expect(dashboard.get('aside nav a').text()).toBe('ພາບລວມ');
        expect(dashboard.get('main').text()).toContain(lo.documentOverview);
        expect(dashboard.get('select').attributes('aria-label')).toBe('ພາສາ');
        expect(document.documentElement.lang).toBe('lo');
        expect(document.title).toBe('ພາບລວມ · DCMS');
        expect(localStorage.getItem('dcms.language')).toBe('lo');
        dashboard.unmount();

        const incoming = mountWorkspace('incoming');

        expect(incoming.get('h1').text()).toBe('ເອກະສານຂາເຂົ້າ');
        expect(incoming.get('select').element.value).toBe('lo');
        incoming.unmount();

        const refreshed = mountWorkspace('incoming');

        expect(refreshed.get('h1').text()).toBe('ເອກະສານຂາເຂົ້າ');
        await refreshed.get('select').setValue('en');

        expect(refreshed.get('h1').text()).toBe('Incoming');
        expect(document.documentElement.lang).toBe('en');
        expect(document.title).toBe('Incoming · DCMS');
        expect(localStorage.getItem('dcms.language')).toBe('en');
    });

    it.each(['unsupported', '__proto__', 'constructor'])('falls back to English for invalid stored language %s', (value) => {
        localStorage.setItem('dcms.language', value);

        const wrapper = mountWorkspace();

        expect(wrapper.get('h1').text()).toBe('Dashboard');
        expect(wrapper.get('select').element.value).toBe('en');
        expect(document.documentElement.lang).toBe('en');
    });

    it('keeps the application and switcher usable when browser storage is blocked', async () => {
        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('Storage blocked'); });
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('Storage blocked'); });

        const wrapper = mountWorkspace();
        await wrapper.get('select').setValue('lo');

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
