import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import IncomingCorrespondence from '../../resources/js/components/IncomingCorrespondence.vue';
import OutgoingCorrespondence from '../../resources/js/components/OutgoingCorrespondence.vue';
import ControlledDocuments from '../../resources/js/components/ControlledDocuments.vue';
import DocumentStorage from '../../resources/js/components/DocumentStorage.vue';
import { createLocalization } from '../../resources/js/localization/index.js';
import en from '../../resources/js/localization/en.js';
import lo from '../../resources/js/localization/lo.js';

const type = { id: 9, name_en: 'Procedure', name_lo: 'ລະບຽບ', category: { id: 8, name_en: 'Documents', name_lo: 'ເອກະສານ' } };
const registers = [
    { kind: 'incoming', component: IncomingCorrespondence, createKey: 'incomingCreate', fields: { type: 9, channel: 'email', sender_organization: 'Example sender', subject: 'Example incoming', correspondence_date: '25/10/2026', received_date: '26/10/2026' }, dateKey: 'correspondence_date' },
    { kind: 'outgoing', component: OutgoingCorrespondence, createKey: 'outgoingCreate', fields: { type: 9, subject: 'Example outgoing', signatory_name: 'Example officer', document_date: '25/10/2026', 'recipient-0-organization': 'Example recipient' }, dateKey: 'document_date' },
    { kind: 'controlled', component: ControlledDocuments, createKey: 'controlledCreate', fields: { type: 9, document_code: 'XPPL/SOP/001', title_en: 'Example procedure', owner_id: 5, custodian_id: 5, issue_date: '25/10/2026', effective_date: '26/10/2026', revision_reason: 'Initial', change_description: 'Initial issue' }, dateKey: 'issue_date' },
];
enableAutoUnmount(afterEach);
beforeEach(() => localStorage.clear());
afterEach(() => { document.body.replaceChildren(); vi.unstubAllGlobals(); });
const response = (body, status = 200) => ({ ok: status < 400, status, json: async () => structuredClone(body) });
function button(wrapper, text) { const result = wrapper.findAll('button').find(item => item.text() === text); expect(result, text).toBeDefined(); return result; }

it.each(registers.flatMap(register => ['en', 'lo'].map(language => ({ ...register, language }))))('saves a $kind draft and attachment through existing APIs in $language', async ({ kind, component, createKey, fields, dateKey, language }) => {
    const dictionary = language === 'lo' ? lo : en;
    const scope = { key: '7:site', company_id: 4, site_id: 7, department_id: null, permissions: [`${kind}.drafts.manage`, 'documents.attachments.manage'] };
    const endpoint = `/${kind}`, storageUrl = `/storage/${kind}/1`;
    const revision = { id: 11, identifier: 'R00', status: 'draft', title_en: 'Example procedure', issue_date: '2026-10-25', effective_date: '2026-10-26' };
    let record = null, attachment = null;
    const fetch = vi.fn(async (url, options = {}) => {
        const path = new URL(url, 'http://localhost').pathname;
        if (path === `${storageUrl}/attachments`) {
            if (options.method === 'POST') {
                attachment = { id: 'attachment-a', versions: [{ id: 'version-a', version: 1, original_name: options.body.get('file').name, reason: options.body.get('reason'), size: 30, uploaded_by: 5, uploaded_at: '2026-10-26T10:00:00Z', mime_type: 'application/pdf', previewable: true }] };
                return response({ data: attachment }, 201);
            }
            return response({ data: attachment ? [attachment] : [], writable: true, capabilities: { preview: false, download: false }, max_upload_kb: 25600 });
        }
        if (path === `${storageUrl}/copies`) { return response({ data: [], history: [], locations: [], people: [] }); }
        if (path.endsWith('/lookups')) { return response({ data: [type], people: [{ id: 5, name: 'Example officer' }] }); }
        if (options.method === 'POST' && path === endpoint) {
            const body = JSON.parse(options.body);
            record = { ...body, id: 1, type_id: 9, type, category: type.category, storage_url: storageUrl, status: 'draft', internal_number: null, lock_version: 1, revisions: [{ ...revision, ...body }], authoritative_revision_id: null, current_revision_id: null };
            return response({ data: record }, 201);
        }
        if (path === endpoint) { return response({ data: record ? [record] : [], meta: { total: record ? 1 : 0 } }); }
        if (path === `${endpoint}/1`) { return response({ data: record, audits: [], related: [] }); }
        throw new Error(`Unexpected request: ${url}`);
    });
    vi.stubGlobal('fetch', fetch);
    const wrapper = mount(component, { props: { scope, endpoint, csrfToken: 'sample-csrf', ...(kind === 'outgoing' ? { incomingEndpoint: '/incoming' } : {}) }, attachTo: document.body, global: { plugins: [createLocalization(language)], stubs: { ConfirmDialog: true } } });
    await flushPromises();
    await button(wrapper, dictionary[createKey]).trigger('click');
    expect(wrapper.text()).toContain(dictionary[kind === 'controlled' ? 'controlledAttachmentHelp' : 'storageDraftHelp']);
    expect(wrapper.find('input[type="file"]').exists()).toBe(kind === 'controlled');
    for (const [field, value] of Object.entries(fields)) { await wrapper.get(`#${kind}-${field}`).setValue(value); }
    if (kind === 'controlled') {
        const input = wrapper.get('#controlled-attachment-file');
        Object.defineProperty(input.element, 'files', { value: [new File(['%PDF-test'], 'signed-original.pdf', { type: 'application/pdf' })], configurable: true });
        await input.trigger('change');
        await wrapper.get('#controlled-attachment-reason').setValue('Signed original');
    }
    await wrapper.get(`#${kind}-draft-form`).trigger('submit'); await flushPromises();
    expect(record[dateKey]).toBe('2026-10-25');
    const panel = wrapper.getComponent(DocumentStorage);
    expect(panel.props('endpoint')).toBe(storageUrl);
    if (kind !== 'controlled') {
        const details = panel.get('details'); details.element.open = true; await details.trigger('toggle'); await flushPromises();
        await button(panel, dictionary.storageUpload).trigger('click');
        const input = panel.get('input[type="file"]');
        Object.defineProperty(input.element, 'files', { value: [new File(['%PDF-test'], 'signed-original.pdf', { type: 'application/pdf' })], configurable: true });
        await input.trigger('change');
        await panel.get('#storage-upload-reason').setValue('Signed original');
        await panel.get('#storage-upload-form').trigger('submit'); await flushPromises();
    } else {
        expect(panel.element.tagName).toBe('SECTION');
        expect(panel.find('#storage-revision').exists()).toBe(false);
    }
    const upload = fetch.mock.calls.find(([url, options]) => url.startsWith(`${storageUrl}/attachments`) && options.method === 'POST');
    expect(upload[1].body).toBeInstanceOf(FormData);
    expect(upload[1].headers['X-CSRF-TOKEN']).toBe('sample-csrf');
    expect(new URL(upload[0], 'http://localhost').searchParams.get('revision_id')).toBe(kind === 'controlled' ? '11' : null);
    expect(panel.text()).toContain('signed-original.pdf');
    // Saves are confirmed with a SweetAlert2 popup; the storage panel also keeps its inline status.
    expect(document.querySelector('.swal2-container .swal2-popup')?.textContent).toContain(dictionary[kind === 'controlled' ? 'controlledSavedWithAttachment' : 'storageSaved']);
    if (kind !== 'controlled') { expect(panel.text()).toContain(dictionary.storageSaved); }
});
