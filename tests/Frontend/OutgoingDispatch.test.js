import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import OutgoingDispatch from '../../resources/js/components/OutgoingDispatch.vue';
import { createLocalization } from '../../resources/js/localization/index.js';

const scope = { company_id: 4, site_id: 7, department_id: null, permissions: ['outgoing.operations.manage', 'outgoing.operations.reopen'] };
const record = { id: 3, status: 'registered', operations: { status: 'registered', revision: 2 }, recipients: [{ id: 12, kind: 'primary', organization: 'Example recipient', delivery: null }] };
enableAutoUnmount(afterEach);
beforeEach(() => localStorage.clear());
afterEach(() => { vi.unstubAllGlobals(); document.body.replaceChildren(); });
function response(body, status = 200) { return { ok: status < 400, status, json: async () => structuredClone(body) }; }
function button(wrapper, label) { const result = wrapper.findAll('button').find(item => item.text() === label); expect(result, label).toBeDefined(); return result; }
function render(extra = {}) {
    const fetch = vi.fn(async (url, options) => options.method === 'POST' ? response({ data: record }) : response({ data: [] }));
    vi.stubGlobal('fetch', fetch);
    const wrapper = mount(OutgoingDispatch, { props: { record: structuredClone(record), scope: structuredClone(scope), endpoint: '/outgoing', incomingEndpoint: '/incoming', csrfToken: 'sample-csrf', ...extra }, global: { plugins: [createLocalization()] } });
    return { wrapper, fetch };
}

describe('outgoing dispatch controls', () => {
    it('records dispatch with its revision, references and CSRF while preserving registration identity', async () => {
        const { wrapper, fetch } = render();
        await button(wrapper, 'Record dispatch').trigger('click');
        await wrapper.get('#outgoing-dispatch-date').setValue('2026-10-25');
        await wrapper.get('#outgoing-dispatch-channel').setValue('courier');
        await wrapper.get('#outgoing-dispatch-reference').setValue('EXAMPLE/TRACK');
        await wrapper.get('form').trigger('submit'); await flushPromises();
        const [url, request] = fetch.mock.calls[0];
        expect(url).toContain('/3/operations?company_id=4&site_id=7');
        expect(request.headers['X-CSRF-TOKEN']).toBe('sample-csrf');
        const body = JSON.parse(request.body);
        expect(body).toMatchObject({ action: 'dispatch', revision: 2, dispatch_channel: 'courier', dispatch_date: '2026-10-25', dispatch_reference: 'EXAMPLE/TRACK' });
        expect(body).not.toHaveProperty('internal_number'); expect(body).not.toHaveProperty('official_number');
        expect(wrapper.emitted('saved')).toHaveLength(1);
        await wrapper.setProps({ record: { ...record, operations: { status: 'dispatched', revision: 3, dispatch_date: '2026-10-25', dispatch_channel: 'courier', dispatch_reference: 'EXAMPLE/TRACK' } } });
        await button(wrapper, 'Correct dispatch').trigger('click');
        await wrapper.get('#outgoing-dispatch-date').setValue('');
        await wrapper.get('#outgoing-operation-note').setValue('Dispatch recorded in error');
        await wrapper.get('form').trigger('submit'); await flushPromises();
        expect(JSON.parse(fetch.mock.calls.at(-1)[1].body)).toMatchObject({ action: 'correct_dispatch', revision: 3, dispatch_date: null, dispatch_channel: null, dispatch_reference: null, dispatch_remarks: null, note: 'Dispatch recorded in error' });

    });
    it('loads the selected recipient acknowledgment and sends a reasoned confirmation correction', async () => {
        const { wrapper, fetch } = render({ record: { ...record, operations: { status: 'delivered', revision: 3 }, recipients: [{ ...record.recipients[0], delivery: { delivery_date: '2026-10-25', acknowledged_by: 'Example receiver', acknowledgment_date: '2026-10-25', acknowledgment_reference: 'EXAMPLE/SIGNED' } }] } });
        await button(wrapper, 'Delivery / acknowledgment').trigger('click');
        await wrapper.get('#outgoing-delivery-recipient').setValue(12);
        expect(wrapper.get('#outgoing-acknowledged-by').element.value).toBe('Example receiver');
        await wrapper.get('#outgoing-delivery-date').setValue('');
        await wrapper.get('#outgoing-operation-note').setValue('Wrong recipient confirmation');
        await wrapper.get('form').trigger('submit'); await flushPromises();
        expect(JSON.parse(fetch.mock.calls[0][1].body)).toMatchObject({ action: 'delivery', revision: 3, recipient_id: 12, delivery_date: null, acknowledged_by: null, acknowledgment_date: null, acknowledgment_reference: null, note: 'Wrong recipient confirmation' });
    });
    it('retains unsaved notes after a stale response and forwards revoked access to the parent', async () => {
        const { wrapper, fetch } = render();
        fetch.mockResolvedValueOnce(response({ message: 'outgoingOperationStale' }, 409));
        await button(wrapper, 'Add operational remark').trigger('click');
        await wrapper.get('#outgoing-operation-note').setValue('Still required');
        await wrapper.get('form').trigger('submit'); await flushPromises();
        expect(wrapper.get('[role="alert"]').text()).toContain('Open its details again');
        expect(wrapper.get('#outgoing-operation-note').element.value).toBe('Still required');
        fetch.mockResolvedValueOnce(response({}, 403));
        await wrapper.get('form').trigger('submit'); await flushPromises();
        expect(wrapper.emitted('failure')[0][0].status).toBe(403);
        const signal = fetch.mock.calls[0][1].signal; wrapper.unmount(); expect(signal.aborted).toBe(true);
    });
    it('keeps readers and closed records without reopen authority from seeing mutation controls and escapes history', () => {
        const { wrapper } = render({ scope: { ...scope, permissions: [] }, history: [{ id: 1, action: 'note', actor_name: 'Example operator', note: '<script>Audit note</script>', before: {}, after: {}, created_at: '2026-10-26' }] });
        expect(wrapper.find('button').exists()).toBe(false); expect(wrapper.text()).toContain('<script>Audit note</script>'); expect(wrapper.find('script').exists()).toBe(false);
        const closed = render({ record: { ...record, operations: { status: 'closed', revision: 4 } }, scope: { ...scope, permissions: ['outgoing.operations.manage'] } });
        expect(closed.wrapper.find('button').exists()).toBe(false);
    });
    it('requires a deliberate reopen reason before sending the operation', async () => {
        const { wrapper, fetch } = render({ record: { ...record, operations: { status: 'closed', revision: 4 } } });
        await button(wrapper, 'Reopen correspondence').trigger('click');
        expect(fetch).not.toHaveBeenCalled();
        expect(wrapper.get('#outgoing-operation-note').attributes()).toHaveProperty('required');
        await wrapper.get('#outgoing-operation-note').setValue('Further handling needed');
        await wrapper.get('form').trigger('submit'); await flushPromises();
        expect(JSON.parse(fetch.mock.calls[0][1].body)).toMatchObject({ action: 'reopen', revision: 4, note: 'Further handling needed' });
    });
    it('finds incoming records in the exact context and refreshes redacted links if detail access is revoked', async () => {
        const item = { id: 9, subject: '<script>Related incoming</script>', internal_number: 'TEST/IN/9' };
        const { wrapper, fetch } = render({ relatedIncoming: [item] });
        await button(wrapper, 'Link incoming correspondence').trigger('click');
        await wrapper.get('#outgoing-incoming-search').setValue('TEST/IN');
        fetch.mockResolvedValueOnce(response({ data: [item] }));
        await button(wrapper, 'Find incoming correspondence').trigger('click'); await flushPromises();
        expect(fetch.mock.calls[0][0]).toContain('search=TEST%2FIN');
        await wrapper.get('#outgoing-related-incoming').setValue(9);
        await wrapper.get('form').trigger('submit'); await flushPromises();
        expect(JSON.parse(fetch.mock.calls[1][1].body)).toMatchObject({ action: 'link', revision: 2, incoming_id: 9 });
        fetch.mockResolvedValueOnce(response({}, 404));
        await button(wrapper, 'TEST/IN/9 · <script>Related incoming</script>').trigger('click'); await flushPromises();
        expect(fetch.mock.calls[2][0]).toBe('/incoming/9?company_id=4&site_id=7');
        expect(wrapper.emitted('saved')).toHaveLength(2);
        expect(wrapper.find('script').exists()).toBe(false);
    });
});
