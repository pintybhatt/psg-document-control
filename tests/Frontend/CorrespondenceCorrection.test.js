import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import CorrespondenceCorrection from '../../resources/js/components/CorrespondenceCorrection.vue';
import IncomingOperations from '../../resources/js/components/IncomingOperations.vue';
import OutgoingDispatch from '../../resources/js/components/OutgoingDispatch.vue';
import { createLocalization } from '../../resources/js/localization/index.js';
import en from '../../resources/js/localization/en.js';
import lo from '../../resources/js/localization/lo.js';

const scope = { company_id: 1, site_id: 1, department_id: null, permissions: ['incoming.operations.manage', 'outgoing.operations.manage'] };
const record = { id: 3, status: 'registered', internal_number: 'IN/2026/001', official_number: 'OFF/2026/001', subject: 'Original subject', sender_organization: 'Sender', external_reference: 'EXT/1', correspondence_date: '2026-10-25', received_date: '2026-10-26', document_date: '2026-10-26', signatory_name: 'Officer', channel: 'email', priority: 'normal', operations: { revision: 2, status: 'registered' }, recipients: [{ id: 7, kind: 'primary', organization: 'Recipient', delivery: null }] };
enableAutoUnmount(afterEach);
beforeEach(() => localStorage.clear());
afterEach(() => vi.unstubAllGlobals());
const response = (body, status = 200) => ({ ok: status < 400, status, json: async () => structuredClone(body) });
function render(kind = 'incoming', language = 'en', extra = {}) {
    const fetch = vi.fn(async () => response({ data: record }));
    vi.stubGlobal('fetch', fetch);
    const wrapper = mount(CorrespondenceCorrection, { props: { kind, record: structuredClone(record), scope: structuredClone(scope), endpoint: `/${kind}`, csrfToken: 'sample-csrf', ...extra }, global: { plugins: [createLocalization(language)] } });
    return { wrapper, fetch };
}

describe('registered correspondence metadata corrections', () => {
    it.each([['incoming', 'en'], ['incoming', 'lo'], ['outgoing', 'en'], ['outgoing', 'lo']])('edits permitted %s details in %s with a reason and the original revision', async (kind, language) => {
        const dictionary = language === 'lo' ? lo : en;
        const { wrapper, fetch } = render(kind, language);
        expect(wrapper.get('button').text()).toBe(dictionary.correspondenceCorrectDetails);
        await wrapper.get('button').trigger('click');
        expect(wrapper.find('h3').text()).toBe(dictionary.correspondenceCorrectDetails);
        expect(wrapper.get(`#${kind}-correction-reason`).attributes()).toMatchObject({ required: '', minlength: '10' });
        expect(wrapper.find('input[name="internal_number"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('IN/2026/001');
        const date = kind === 'incoming' ? 'received_date' : 'document_date';
        expect(wrapper.get(`#${kind}-correction-${date}`).element.value).toBe('26/10/2026');
        await wrapper.get(`#${kind}-correction-${date}`).setValue('27/10/2026');
        await wrapper.get(`#${kind}-correction-subject`).setValue('Corrected subject');
        expect(wrapper.get(`#${kind}-correction-document_revision`).attributes('maxlength')).toBe('30');
        await wrapper.get(`#${kind}-correction-document_revision`).setValue('01');
        await wrapper.get(`#${kind}-correction-reason`).setValue('Correct transcription from original');
        if (kind === 'outgoing') { await wrapper.get('#outgoing-correction-recipient-0-organization').setValue('Correct recipient'); }
        await wrapper.get('form').trigger('submit'); await flushPromises();
        const [url, request] = fetch.mock.calls[0];
        expect(url).toBe(`/${kind}/3/operations?company_id=1&site_id=1`);
        expect(request.headers['X-CSRF-TOKEN']).toBe('sample-csrf');
        const body = JSON.parse(request.body);
        expect(body).toMatchObject({ action: 'correct_details', revision: 2, note: 'Correct transcription from original', details: { subject: 'Corrected subject', document_revision: '01', [date]: '2026-10-27' } });
        expect(body.details).not.toHaveProperty('internal_number');
        expect(body.details).not.toHaveProperty('official_number');
        expect(body.details).not.toHaveProperty('classification');
        expect(body.details).not.toHaveProperty('type_id');
        if (kind === 'outgoing') { expect(body.details.recipients[0]).toMatchObject({ id: 7, organization: 'Correct recipient' }); expect(body.details.recipients[0]).not.toHaveProperty('kind'); }
        expect(wrapper.emitted('saved')).toHaveLength(1);
        expect(wrapper.find('form').exists()).toBe(false);
    });
    it('hides corrections for readers, drafts, closed documents and outgoing documents with prior dispatch', () => {
        for (const kind of ['incoming', 'outgoing']) {
            expect(render(kind, 'en', { scope: { ...scope, permissions: [] } }).wrapper.find('button').exists()).toBe(false);
            expect(render(kind, 'en', { record: { ...record, status: 'draft' } }).wrapper.find('button').exists()).toBe(false);
            expect(render(kind, 'en', { record: { ...record, operations: { status: 'closed' } } }).wrapper.find('button').exists()).toBe(false);
        }
        expect(render('outgoing', 'en', { history: [{ action: 'dispatch' }, { action: 'correct_dispatch' }] }).wrapper.find('button').exists()).toBe(false);
        expect(render('outgoing', 'en', { record: { ...record, operations: { status: 'dispatched' } } }).wrapper.find('button').exists()).toBe(false);
    });
    it('keeps invalid and stale edits visible and forwards access revocation', async () => {
        const { wrapper, fetch } = render();
        await wrapper.get('button').trigger('click');
        await wrapper.get('#incoming-correction-subject').setValue('Unsaved correction');
        fetch.mockResolvedValueOnce(response({ errors: { note: ['incomingValidation'] } }, 422));
        await wrapper.get('form').trigger('submit'); await flushPromises();
        expect(wrapper.get('#incoming-correction-reason').attributes('aria-invalid')).toBe('true');
        fetch.mockResolvedValueOnce(response({ message: 'incomingOperationStale' }, 409));
        await wrapper.get('form').trigger('submit'); await flushPromises();
        expect(wrapper.get('[role="alert"]').text()).toContain('Open its details again');
        expect(wrapper.get('#incoming-correction-subject').element.value).toBe('Unsaved correction');
        expect(wrapper.emitted('saved')).toBeUndefined();
        fetch.mockResolvedValueOnce(response({}, 403));
        await wrapper.get('form').trigger('submit'); await flushPromises();
        expect(wrapper.emitted('failure')[0][0].status).toBe(403);
    });
    it.each(['incoming', 'outgoing'])('renders %s correction history with localized fields, date-only values and Laos timestamps', kind => {
        const component = kind === 'incoming' ? IncomingOperations : OutgoingDispatch;
        const history = [{ id: 1, action: kind === 'incoming' ? 'updated' : 'note', actor_name: 'Officer', note: 'Correct original transcription', before: { details: { subject: '<script>Original</script>', [kind === 'incoming' ? 'received_date' : 'document_date']: '2026-10-25 00:00:00' } }, after: { details: { subject: 'Corrected', [kind === 'incoming' ? 'received_date' : 'document_date']: '2026-10-26 00:00:00' } }, created_at: '2026-10-26T18:35:00Z' }];
        const wrapper = mount(component, { props: { record, scope: { ...scope, permissions: [] }, endpoint: '/test', csrfToken: 'sample', history }, global: { plugins: [createLocalization()] } });
        expect(wrapper.text()).toContain('Correct details');
        expect(wrapper.text()).toContain('27/10/2026, 01:35');
        expect(wrapper.text()).toContain('25/10/2026 → 26/10/2026');
        expect(wrapper.text()).toContain('<script>Original</script> → Corrected');
        expect(wrapper.find('script').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('[object Object]');
    });
});
