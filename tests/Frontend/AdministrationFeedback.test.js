import { afterEach, describe, expect, it, vi } from 'vitest';
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import BusyOverlay from '../../resources/js/components/ui/BusyOverlay.vue';
import FormModal from '../../resources/js/components/ui/FormModal.vue';
import SweetConfirmDialog from '../../resources/js/components/ui/SweetConfirmDialog.vue';
import OrganizationManagement from '../../resources/js/components/OrganizationManagement.vue';
import { createJsonClient } from '../../resources/js/utilities/jsonClient.js';
import { createLocalization } from '../../resources/js/localization/index.js';
import { settle, swalCancel, swalConfirm, swalText, swalTitle } from './swal.js';

enableAutoUnmount(afterEach);
afterEach(() => { vi.unstubAllGlobals(); localStorage.clear(); document.body.replaceChildren(); document.body.style.overflow = ''; });
const global = { plugins: [createLocalization()] };
const reply = (data, status = 200) => ({ ok: status < 400, status, json: async () => structuredClone(data) });

describe('administration feedback', () => {
    it('shows the spinner overlay only while a data-changing request is in flight', async () => {
        let finish;
        vi.stubGlobal('fetch', vi.fn((url, options = {}) => (options.method === 'POST' ? new Promise(resolve => { finish = resolve; }) : Promise.resolve(reply({ data: [] })))));
        mount(BusyOverlay, { attachTo: document.body, global });
        const request = createJsonClient('csrf');

        await request('/items');
        await flushPromises();
        expect(document.querySelector('[data-busy-overlay]')).toBeNull();

        const preview = request('/items/preview', { method: 'POST', busy: false });
        await flushPromises();
        expect(document.querySelector('[data-busy-overlay]')).toBeNull();
        finish(reply({ number: 'X-1' }));
        await preview;

        const saving = request('/items', { method: 'POST', body: {} });
        await flushPromises();
        expect(document.querySelector('[data-busy-overlay]')?.textContent).toContain('Saving…');
        finish(reply({ data: {} }, 201));
        await saving;
        await flushPromises();
        expect(document.querySelector('[data-busy-overlay]')).toBeNull();
    });

    it('opens a modal card that closes from Escape or the close button, but not while busy', async () => {
        const wrapper = mount(FormModal, { attachTo: document.body, props: { title: 'Edit record', subtitle: 'Fictional record' }, slots: { default: '<form><input name="example" /></form>' }, global });
        await flushPromises();
        const panel = wrapper.get('[role="dialog"]');
        expect(panel.attributes('aria-modal')).toBe('true');
        expect(document.getElementById(panel.attributes('aria-labelledby')).textContent).toBe('Edit record');
        expect(document.body.style.overflow).toBe('hidden');

        await panel.trigger('keydown', { key: 'Escape' });
        await wrapper.get('[data-modal-close]').trigger('click');
        expect(wrapper.emitted('close')).toHaveLength(2);

        await wrapper.setProps({ busy: true });
        await panel.trigger('keydown', { key: 'Escape' });
        expect(wrapper.emitted('close')).toHaveLength(2);
        expect(wrapper.get('[data-modal-close]').element.disabled).toBe(true);
    });

    it('confirms with SweetAlert2 and reports cancel without confirming', async () => {
        const wrapper = mount(SweetConfirmDialog, { attachTo: document.body, props: { modelValue: true, title: 'Revoke access', message: 'Example Account · Viewer', danger: true, 'onUpdate:modelValue': value => wrapper.setProps({ modelValue: value }) }, global });
        await settle();
        expect(swalTitle()).toBe('Revoke access');
        expect(swalText()).toContain('Example Account · Viewer');
        await swalCancel();
        expect(wrapper.emitted('cancel')).toHaveLength(1);
        expect(wrapper.emitted('confirm')).toBeUndefined();

        await wrapper.setProps({ modelValue: true });
        await swalConfirm();
        expect(wrapper.emitted('confirm')).toHaveLength(1);
        expect(wrapper.props('modelValue')).toBe(false);
    });

    it('edits organization records in a modal card and closes it after saving', async () => {
        const company = { id: 1, code: 'SYNTHETIC', name_en: 'Synthetic Company', name_lo: 'ບໍລິສັດຕົວຢ່າງ', active: true };
        const fetch = vi.fn(async (url, options = {}) => (options.method && options.method !== 'GET' ? reply({ data: company }) : reply({ data: [company], meta: { total: 1 } })));
        vi.stubGlobal('fetch', fetch);
        const wrapper = mount(OrganizationManagement, { attachTo: document.body, props: { endpoint: '/api/v1/organization/companies', csrfToken: 'csrf' }, global });
        await flushPromises();
        expect(wrapper.find('[data-form-modal]').exists()).toBe(false);

        await wrapper.findAll('button').find(item => item.text() === 'Edit').trigger('click');
        await flushPromises();
        const modal = wrapper.get('[data-form-modal]');
        expect(modal.get('[role="dialog"]').text()).toContain('Edit');
        await modal.get('input[name="org-name-en"]').setValue('Renamed Company');
        await modal.get('form').trigger('submit');
        await flushPromises();

        expect(JSON.parse(fetch.mock.calls.find(([, options]) => options.method === 'PATCH')[1].body)).toMatchObject({ name_en: 'Renamed Company' });
        expect(wrapper.find('[data-form-modal]').exists()).toBe(false);
        expect(wrapper.emitted('saved')).toHaveLength(1);
    });
});
