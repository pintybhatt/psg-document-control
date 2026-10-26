import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import IncomingOperations from '../../resources/js/components/IncomingOperations.vue';
import { createLocalization } from '../../resources/js/localization/index.js';

const scope = { company_id: 4, site_id: 7, department_id: null, permissions: ['incoming.operations.manage', 'incoming.operations.reopen'] };
const record = { id: 3, status: 'registered', operations: { status: 'received', revision: 2, response_required: false, response_completed: false } };
const options = { users: [{ id: 12, name: 'Example receiver' }], departments: [{ id: 8, name_en: 'Receiving department', name_lo: 'ພະແນກຮັບ' }], related: [{ id: 9, subject: '<script>Related subject</script>', internal_number: 'TEST/IN/9' }] };
enableAutoUnmount(afterEach);
beforeEach(() => localStorage.clear());
afterEach(() => { vi.unstubAllGlobals(); document.body.replaceChildren(); });
function response(body, status = 200) { return { ok: status < 400, status, json: async () => structuredClone(body) }; }
function button(wrapper, text) { return wrapper.findAll('button').find(item => item.text() === text); }
function render(extra = {}, failure = null) {
    const fetch = vi.fn(async (url, request) => request.method === 'POST' ? failure ? response(failure.body, failure.status) : response({ data: record }) : response(options));
    vi.stubGlobal('fetch', fetch);
    const wrapper = mount(IncomingOperations, { props: { record: structuredClone(record), scope: structuredClone(scope), endpoint: '/incoming', csrfToken: 'sample-csrf', ...extra }, global: { plugins: [createLocalization()] } });
    return { wrapper, fetch };
}

describe('incoming operational controls', () => {
    it('sends assignment, due date and response changes with CSRF and the loaded revision', async () => {
        const { wrapper, fetch } = render();
        await button(wrapper, 'Update handling').trigger('click'); await flushPromises();
        await wrapper.get('#incoming-responsible-user').setValue(12);
        await wrapper.get('#incoming-responsible-department').setValue(8);
        await wrapper.get('#incoming-due-date').setValue('2026-11-01');
        await wrapper.get('#incoming-response-required').setValue(true);
        await wrapper.get('form').trigger('submit'); await flushPromises();
        const [url, request] = fetch.mock.calls.find(([, request]) => request.method === 'POST');
        expect(url).toContain('/3/operations?company_id=4&site_id=7');
        expect(request.headers['X-CSRF-TOKEN']).toBe('sample-csrf');
        expect(JSON.parse(request.body)).toMatchObject({ action: 'update', revision: 2, responsible_user_id: 12, responsible_department_id: 8, due_date: '2026-11-01', response_required: true });
        expect(JSON.parse(request.body)).not.toHaveProperty('internal_number');
        expect(wrapper.emitted('saved')).toHaveLength(1);
    });
    it('retains an unsaved correction and shows a useful stale-update error', async () => {
        const { wrapper } = render({}, { status: 409, body: { message: 'incomingOperationStale' } });
        await button(wrapper, 'Add follow-up').trigger('click');
        await wrapper.get('#incoming-operation-note').setValue('Follow-up still needed');
        await wrapper.get('form').trigger('submit'); await flushPromises();
        expect(wrapper.get('[role="alert"]').text()).toContain('Open its details again');
        expect(wrapper.get('#incoming-operation-note').element.value).toBe('Follow-up still needed');
        expect(wrapper.emitted('saved')).toBeUndefined();
    });
    it('requires an explicit reopen form and reason, and hides handling changes while closed', async () => {
        const { wrapper, fetch } = render({ record: { ...record, operations: { ...record.operations, status: 'closed', closure_remarks: 'Finished' } } });
        expect(button(wrapper, 'Update handling')).toBeUndefined();
        await button(wrapper, 'Reopen correspondence').trigger('click');
        expect(wrapper.get('#incoming-operation-note').attributes()).toHaveProperty('required');
        expect(fetch).not.toHaveBeenCalled();
        await wrapper.get('#incoming-operation-note').setValue('Correct closure');
        await wrapper.get('form').trigger('submit'); await flushPromises();
        expect(JSON.parse(fetch.mock.calls[0][1].body)).toMatchObject({ action: 'reopen', note: 'Correct closure', revision: 2 });
    });
    it('finds a related item by number and sends its selected identifier without rendering markup', async () => {
        const { wrapper, fetch } = render();
        await button(wrapper, 'Link correspondence').trigger('click'); await flushPromises();
        await wrapper.get('#incoming-related-search').setValue('TEST/IN');
        await button(wrapper, 'Find correspondence').trigger('click'); await flushPromises();
        expect(fetch.mock.calls[1][0]).toContain('search=TEST%2FIN');
        expect(wrapper.find('script').exists()).toBe(false);
        await wrapper.get('#incoming-related').setValue(9);
        await wrapper.get('form').trigger('submit'); await flushPromises();
        expect(JSON.parse(fetch.mock.calls.at(-1)[1].body)).toMatchObject({ action: 'link', related_id: 9 });
    });
    it('aborts pending choices and forwards access revocation to clear the parent record', async () => {
        const { wrapper, fetch } = render({}, { status: 403, body: {} });
        await button(wrapper, 'Add follow-up').trigger('click');
        await wrapper.get('#incoming-operation-note').setValue('Note');
        await wrapper.get('form').trigger('submit'); await flushPromises();
        expect(wrapper.emitted('failure')[0][0].status).toBe(403);
        const signal = fetch.mock.calls[0][1].signal;
        wrapper.unmount(); expect(signal.aborted).toBe(true);
    });
    it('keeps readers and users without reopen authority from seeing mutation controls', () => {
        const { wrapper } = render({ scope: { ...scope, permissions: [] }, history: [{ id: 1, action: 'noted', actor_name: 'Example receiver', note: '<script>History</script>', before: {}, after: {}, created_at: '2026-10-26' }] });
        expect(wrapper.find('button').exists()).toBe(false);
        expect(wrapper.text()).toContain('<script>History</script>');
        expect(wrapper.find('script').exists()).toBe(false);
        const closed = render({ record: { ...record, operations: { ...record.operations, status: 'closed' } }, scope: { ...scope, permissions: ['incoming.operations.manage'] } });
        expect(button(closed.wrapper, 'Reopen correspondence')).toBeUndefined();
    });
});
