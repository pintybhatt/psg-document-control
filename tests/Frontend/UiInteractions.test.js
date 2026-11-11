import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import { ref } from 'vue';
import ConfirmDialog from '../../resources/js/components/ui/ConfirmDialog.vue';
import ToastNotifications from '../../resources/js/components/ui/ToastNotifications.vue';
import { useNotifications } from '../../resources/js/components/ui/useNotifications.js';
import LanguageSwitcher from '../../resources/js/components/LanguageSwitcher.vue';
import App from '../../resources/js/App.vue';
import DataTable from '../../resources/js/components/ui/DataTable.vue';
import { createLocalization } from '../../resources/js/localization/index.js';

enableAutoUnmount(afterEach);
afterEach(() => document.body.replaceChildren());
beforeEach(() => localStorage.clear());

const dialogDescriptors = Object.getOwnPropertyDescriptors(HTMLDialogElement.prototype);

beforeAll(() => {
    // jsdom does not implement the browser's modal top layer or focus trap.
    Object.defineProperties(HTMLDialogElement.prototype, {
        showModal: { configurable: true, value: vi.fn(function () { this.setAttribute('open', ''); }) },
        close: { configurable: true, value: vi.fn(function () {
            if (this.open) {
                this.removeAttribute('open');
                this.dispatchEvent(new Event('close'));
            }
        }) },
    });
});

afterAll(() => {
    for (const name of ['showModal', 'close']) {
        if (dialogDescriptors[name]) {
            Object.defineProperty(HTMLDialogElement.prototype, name, dialogDescriptors[name]);
        } else {
            delete HTMLDialogElement.prototype[name];
        }
    }
});

function render(component, options = {}) {
    return mount(component, { attachTo: document.body, global: { plugins: [createLocalization()] }, ...options });
}

function renderDialog(danger = false) {
    return render({
        components: { ConfirmDialog },
        setup: () => ({ open: ref(false), loading: ref(false), danger, confirmations: ref(0) }),
        template: '<div><button id="launch" @click="open = true">Open</button><ConfirmDialog v-model="open" :loading="loading" :danger="danger" title="Confirm sample action?" message="Only fictional data is used." @confirm="confirmations++" /></div>',
    });
}

describe('confirmation dialogs', () => {
    it('opens modally with an accessible name and description, focuses Cancel and restores the opener on Escape', async () => {
        const wrapper = renderDialog(true);
        const opener = wrapper.get('#launch');
        opener.element.focus();

        await opener.trigger('click');
        await flushPromises();
        const dialog = wrapper.get('dialog');
        expect(dialog.element.open).toBe(true);
        expect(dialog.element.showModal).toHaveBeenCalled();
        expect(document.getElementById(dialog.attributes('aria-labelledby')).textContent).toBe('Confirm sample action?');
        expect(document.getElementById(dialog.attributes('aria-describedby')).textContent).toBe('Only fictional data is used.');
        expect(document.activeElement).toBe(wrapper.get('[data-cancel]').element);

        await dialog.trigger('cancel');
        await flushPromises();

        expect(dialog.element.open).toBe(false);
        expect(wrapper.vm.open).toBe(false);
        expect(wrapper.vm.confirmations).toBe(0);
        expect(document.activeElement).toBe(opener.element);
    });

    it('emits confirmation only on explicit Confirm, leaving closure to the parent', async () => {
        const wrapper = renderDialog();
        await wrapper.get('#launch').trigger('click');
        await flushPromises();

        await wrapper.get('dialog').findAll('button')[1].trigger('click');

        expect(wrapper.vm.confirmations).toBe(1);
        expect(wrapper.get('dialog').element.open).toBe(true);
        await wrapper.get('[data-cancel]').trigger('click');
        await flushPromises();
        expect(wrapper.get('dialog').element.open).toBe(false);
        expect(wrapper.vm.confirmations).toBe(1);
    });

    it('blocks confirmation, cancellation and Escape while an action is loading', async () => {
        const wrapper = renderDialog();
        await wrapper.get('#launch').trigger('click');
        await flushPromises();
        wrapper.vm.loading = true;
        await flushPromises();

        expect(wrapper.get('dialog').attributes('aria-busy')).toBe('true');
        expect(wrapper.get('dialog').findAll('button').every(button => button.element.disabled)).toBe(true);
        await wrapper.get('dialog').trigger('cancel');
        await wrapper.get('dialog').findAll('button')[1].trigger('click');

        expect(wrapper.get('dialog').element.open).toBe(true);
        expect(wrapper.vm.confirmations).toBe(0);
    });

    it('closes and restores focus if the dialog is unmounted while open', async () => {
        const opener = document.createElement('button');
        document.body.append(opener);
        opener.focus();
        const wrapper = render(ConfirmDialog, { props: { modelValue: true, title: 'Title', message: 'Message' } });
        await flushPromises();

        wrapper.unmount();

        expect(document.activeElement).toBe(opener);
    });
});

describe('toast notifications', () => {
    describe('automatic dismissal', () => {
        beforeEach(() => vi.useFakeTimers());
        afterEach(() => vi.useRealTimers());

        function notifications() {
            let controls;
            const wrapper = render({
                components: { ToastNotifications },
                setup() { controls = useNotifications(); return controls; },
                template: '<ToastNotifications :items="items" @dismiss="dismiss" />',
            });
            return { wrapper, ...controls };
        }

        it('expires success after four seconds and info after five while preserving warnings and errors', async () => {
            const { wrapper, notify } = notifications();
            for (const type of ['success', 'info', 'warning', 'error']) { notify({ type, message: type }); }
            await vi.advanceTimersByTimeAsync(3999);
            expect(wrapper.findAll('article')).toHaveLength(4);
            await vi.advanceTimersByTimeAsync(1);
            expect(wrapper.findAll('article').map(item => item.get('p + p').text())).toEqual(['info', 'warning', 'error']);
            await vi.advanceTimersByTimeAsync(999);
            expect(wrapper.findAll('article')).toHaveLength(3);
            await vi.advanceTimersByTimeAsync(1);
            expect(wrapper.findAll('article').map(item => item.get('p + p').text())).toEqual(['warning', 'error']);
            await vi.advanceTimersByTimeAsync(60000);
            expect(wrapper.findAll('article')).toHaveLength(2);
            await wrapper.get('article button').trigger('click');
            expect(wrapper.findAll('article')).toHaveLength(1);
        });

        it('keeps consecutive notifications on independent deadlines and cancels manually dismissed timers', async () => {
            const { wrapper, notify } = notifications();
            notify({ type: 'success', message: 'First' });
            await vi.advanceTimersByTimeAsync(3000);
            notify({ type: 'success', message: 'Second' });
            await vi.advanceTimersByTimeAsync(0);
            expect(wrapper.findAll('article')).toHaveLength(2);
            await wrapper.get('article button').trigger('click');
            expect(vi.getTimerCount()).toBe(1);
            await vi.advanceTimersByTimeAsync(1000);
            expect(wrapper.text()).toContain('Second');
            notify({ type: 'info', message: 'Third' });
            await vi.advanceTimersByTimeAsync(3000);
            expect(wrapper.text()).not.toContain('Second');
            expect(wrapper.text()).toContain('Third');
            await vi.advanceTimersByTimeAsync(2000);
            expect(wrapper.findAll('article')).toHaveLength(0);
            expect(vi.getTimerCount()).toBe(0);
        });

        it('clears pending timers when its component unmounts', async () => {
            const { wrapper, notify, items } = notifications();
            notify({ type: 'success', message: 'Success' });
            notify({ type: 'info', message: 'Info' });
            expect(vi.getTimerCount()).toBe(2);
            wrapper.unmount();
            expect(vi.getTimerCount()).toBe(0);
            await vi.advanceTimersByTimeAsync(6000);
            expect(items.value).toHaveLength(2);
        });
    });

    it('announces each severity with text and translates existing notifications live', async () => {
        const wrapper = render({
            components: { ToastNotifications, LanguageSwitcher },
            setup: () => ({ items: ['success', 'warning', 'error', 'info'].map((type, id) => ({ id, type, messageKey: 'uiPreviewNotification' })) }),
            template: '<div><LanguageSwitcher /><ToastNotifications :items="items" /></div>',
        });

        expect(wrapper.findAll('article').map(item => item.attributes('role'))).toEqual(['status', 'alert', 'alert', 'status']);
        expect(wrapper.findAll('article').map(item => item.get('p').text())).toEqual(['Success', 'Warning', 'Error', 'Information']);
        await wrapper.get('[data-language-switcher] input[value="lo"]').setValue(true);
        expect(wrapper.get('article').text()).toContain('ສຳເລັດ');
        expect(wrapper.get('article').text()).toContain('ນີ້ແມ່ນການແຈ້ງເຕືອນຕົວຢ່າງ.');
        expect(wrapper.get('article button').attributes('aria-label')).toBe('ປິດການແຈ້ງເຕືອນ');
    });

    it('dismisses notifications through native buttons and preserves keyboard focus', async () => {
        const wrapper = render({
            components: { ToastNotifications },
            setup() {
                const notifications = useNotifications();
                notifications.notify({ type: 'info', message: 'First' });
                notifications.notify({ type: 'success', message: 'Second' });
                return notifications;
            },
            template: '<ToastNotifications :items="items" @dismiss="dismiss" />',
        });
        const firstButton = wrapper.get('article button');
        firstButton.element.focus();

        await firstButton.trigger('click');
        await flushPromises();
        expect(wrapper.findAll('article')).toHaveLength(1);
        expect(wrapper.text()).toContain('Second');
        expect(document.activeElement).toBe(wrapper.get('article button').element);
        await wrapper.get('article button').trigger('click');
        await flushPromises();
        expect(wrapper.findAll('article')).toHaveLength(0);
        expect(document.activeElement).toBe(wrapper.element);
    });

    it('does not steal focus on arrival and safely escapes message content', () => {
        const field = document.createElement('input');
        document.body.append(field);
        field.focus();
        const wrapper = render(ToastNotifications, { props: { items: [{ id: 1, type: 'info', message: '<script>alert(1)</script>' }] } });

        expect(document.activeElement).toBe(field);
        expect(wrapper.text()).toContain('<script>alert(1)</script>');
        expect(wrapper.find('script').exists()).toBe(false);
    });
});

describe('development preview', () => {
    it('demonstrates bilingual validation, table sorting and pagination, dialogs and notifications', async () => {
        const wrapper = render(App, {
            props: {
                page: 'ui-preview',
                navigation: ['dashboard', 'incoming', 'outgoing', 'controlled-documents', 'document-search', 'reports', 'administration'].map(key => ({ key, href: `/${key}` })),
                context: { company: { id: null, name: 'Phonesack Group' }, site: null },
            },
        });
        await vi.dynamicImportSettled();
        await flushPromises();

        expect(wrapper.text()).toContain('Development only · Fictional sample data');
        expect(wrapper.get('aside nav').findAll('a')).toHaveLength(7);
        await wrapper.get('form').trigger('submit');
        expect(wrapper.findAll('form [role="alert"]')).toHaveLength(3);
        expect(document.activeElement).toBe(wrapper.get('input[name="sample-title"]').element);
        await wrapper.get('[data-language-switcher] input[value="lo"]').setValue(true);
        expect(wrapper.get('form [role="alert"]').text()).toBe('ກະລຸນາປ້ອນຂໍ້ມູນໃນຊ່ອງນີ້.');
        await wrapper.get('input[name="sample-title"]').setValue('Fictional title');
        await wrapper.get('select[name="sample-category"]').setValue('sample');
        await wrapper.get('textarea[name="sample-notes"]').setValue('Fictional notes');
        await wrapper.get('input[name="sample-acknowledged"]').setValue(true);
        await wrapper.get('form').trigger('submit');
        expect(wrapper.findAll('form [role="alert"]')).toHaveLength(0);
        expect(wrapper.findComponent(ToastNotifications).text()).toContain('ບໍ່ມີການບັນທຶກຂໍ້ມູນ.');

        const table = wrapper.findComponent(DataTable);
        await table.findAll('nav button')[1].trigger('click');
        expect(table.get('tbody').text()).toContain('DEMO-REF-004');
        await table.get('th button').trigger('click');
        expect(table.get('tbody tr').text()).toContain('DEMO-REF-008');
        await wrapper.get('section[aria-labelledby="preview-dialogs"] button').trigger('click');
        await flushPromises();
        expect(wrapper.get('dialog').element.open).toBe(true);
        await wrapper.get('dialog').findAll('button')[1].trigger('click');
        await flushPromises();
        expect(wrapper.get('dialog').element.open).toBe(false);
        expect(wrapper.findComponent(ToastNotifications).findAll('article')).toHaveLength(2);
    });
});
