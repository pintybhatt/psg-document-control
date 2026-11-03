import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import DocumentStorage from '../../resources/js/components/DocumentStorage.vue';
import PdfPreviewDialog from '../../resources/js/components/ui/PdfPreviewDialog.vue';
import LanguageSwitcher from '../../resources/js/components/LanguageSwitcher.vue';
import { createLocalization } from '../../resources/js/localization/index.js';
import en from '../../resources/js/localization/en.js';
import lo from '../../resources/js/localization/lo.js';

const scope = { key: '7:site', company_id: 4, site_id: 7, permissions: ['documents.attachments.manage', 'documents.content.preview', 'documents.attachments.download', 'documents.custody.manage', 'storage-locations.manage'] };
const version = { id: 'version-a', version: 1, original_name: '<script>original.pdf</script>', mime_type: 'application/pdf', size: 1024, uploaded_by: 5, uploaded_at: '2026-10-26T18:35:00Z', reason: 'Original', previewable: true };
const copy = { id: 'copy-a', identifier: 'ORIGINAL-001', kind: 'original', status: 'stored', location: 'Archive → A → 2 → 001', location_id: 3, lock_version: 1, overdue: false };
enableAutoUnmount(afterEach);
beforeEach(() => localStorage.clear());
afterEach(() => { document.body.replaceChildren(); vi.unstubAllGlobals(); });
function response(data, status = 200) { return { ok: status < 400, status, json: async () => structuredClone(data) }; }
function server({ writable = true, failure = null, content = true } = {}) {
    const fetch = vi.fn(async (url, options = {}) => {
        if (failure) { return response(failure.body, failure.status); }
        const path = new URL(url, 'http://localhost').pathname;
        if (options.method === 'POST') { return response({ data: {} }); }
        if (path.endsWith('/attachments')) { return response({ data: [{ id: 'attachment-a', versions: [version] }], writable, capabilities: { preview: content, download: content }, max_upload_kb: 25600 }); }
        if (path.endsWith('/copies')) { return response({ data: [copy], history: [{ id: 1, action: 'copy.created', subject_id: copy.id, reason: 'Filed original', created_at: '2026-10-26T20:00:00Z', after: { location_id: 3 } }], locations: [{ id: 3, label: copy.location }], people: [{ id: 5, name: 'Example officer' }] }); }
        throw new Error(`Unexpected request ${url}`);
    });
    vi.stubGlobal('fetch', fetch); return fetch;
}
async function render(properties = {}) {
    const wrapper = mount({ components: { DocumentStorage, LanguageSwitcher }, props: { properties: Object }, template: '<div><LanguageSwitcher /><DocumentStorage v-bind="properties" /></div>' }, { props: { properties: { endpoint: '/storage/incoming/1', scope: structuredClone(scope), csrfToken: 'sample-csrf', ...properties } }, attachTo: document.body, global: { plugins: [createLocalization()], stubs: { PdfPreviewDialog: true } } });
    await flushPromises(); return wrapper;
}
async function expand(wrapper) { const details = wrapper.getComponent(DocumentStorage).get('details'); details.element.open = true; await details.trigger('toggle'); await flushPromises(); }
function button(wrapper, text) { const found = wrapper.findAll('button').find(item => item.text() === text); expect(found, text).toBeDefined(); return found; }

it('loads only on expansion and renders escaped names, bilingual custody and display dates', async () => {
    const fetch = server(); const wrapper = await render(); expect(fetch).not.toHaveBeenCalled();
    await expand(wrapper);
    expect(wrapper.text()).toContain(copy.location); expect(wrapper.find('script').exists()).toBe(false);
    expect(wrapper.text()).toContain('27/10/2026, 01:35'); expect(wrapper.text()).toContain('27/10/2026, 03:00'); expect(wrapper.text()).not.toContain('UTC');
    await wrapper.get('select[aria-label="Language"]').setValue('lo');
    expect(wrapper.text()).toContain(lo.storageTitle); expect(wrapper.text()).toContain(lo['storageAction-checkout']);
    expect(wrapper.text()).not.toMatch(/storage[A-Z-]|uiSave/);
    expect(wrapper.text()).toContain('27/10/2026, 01:35'); expect(wrapper.text()).toContain('27/10/2026, 03:00');
});
it('uploads multipart data with CSRF and expected attachment version for replacements', async () => {
    const fetch = server(); const wrapper = await render(); await expand(wrapper);
    await button(wrapper, en.storageReplace).trigger('click');
    const input = wrapper.get('input[type="file"]'); const file = new File(['%PDF-test'], 'corrected.pdf', { type: 'application/pdf' });
    Object.defineProperty(input.element, 'files', { value: [file], configurable: true }); await input.trigger('change');
    await wrapper.get('#storage-upload-reason').setValue('Corrected scan');
    await wrapper.get('#storage-upload-form').trigger('submit'); await flushPromises();
    const options = fetch.mock.calls.find(([, value]) => value.method === 'POST')[1];
    expect(options.body).toBeInstanceOf(FormData); expect(options.body.get('expected_version')).toBe('1'); expect(options.body.get('reason')).toBe('Corrected scan');
    expect(options.headers['X-CSRF-TOKEN']).toBe('sample-csrf'); expect(options.headers).not.toHaveProperty('Content-Type');
});
it('submits checkout with the reviewed custody version and ISO dates', async () => {
    const fetch = server(); const wrapper = await render(); await expand(wrapper);
    await button(wrapper, en['storageAction-checkout']).trigger('click');
    await wrapper.get('#storage-custodian').setValue(5); await wrapper.get('#storage-checkout-date').setValue('25/10/2026'); await wrapper.get('#storage-return-date').setValue('28/10/2026');
    await wrapper.get('#storage-custody-reason').setValue('Review'); await wrapper.get('#storage-custody-form').trigger('submit'); await flushPromises();
    const body = JSON.parse(fetch.mock.calls.find(([, value]) => value.method === 'POST')[1].body);
    expect(body).toMatchObject({ action: 'checkout', lock_version: 1, custodian_id: 5, checkout_date: '2026-10-25', expected_return_date: '2026-10-28', reason: 'Review' });
});
it('keeps released files immutable and requests the selected controlled revision', async () => {
    const fetch = server({ writable: false }); const wrapper = await render({ revisions: [{ id: 11, identifier: '00', status: 'current' }, { id: 12, identifier: '01', status: 'draft' }], authoritativeRevision: 11 }); await expand(wrapper);
    expect(fetch.mock.calls.every(([url]) => url.includes('revision_id=11'))).toBe(true);
    expect(wrapper.text()).toContain(en.storageRevisionImmutable);
    expect(wrapper.findAll('button').some(item => item.text() === en.storageReplace)).toBe(false);
    await wrapper.get('#storage-revision').setValue(12); await flushPromises();
    expect(fetch.mock.calls.at(-1)[0]).toContain('revision_id=12');
});
it('uses protected PDF preview without a preview-blob download action', async () => {
    server(); const wrapper = await render(); await expand(wrapper);
    await button(wrapper, en.storagePreview).trigger('click'); await flushPromises();
    const preview = wrapper.findComponent(PdfPreviewDialog);
    expect(preview.props('url')).toContain('/versions/version-a/preview'); expect(preview.props('allowDownload')).toBe(false);
});
it('hides write and content actions for metadata-only readers', async () => {
    server({ content: false }); const wrapper = await render({ scope: { ...scope, permissions: [] } }); await expand(wrapper);
    for (const text of [en.storageUpload, en.storagePreview, en.storageDownload, en.storageAddCopy, en.storageManageLocations]) { expect(wrapper.findAll('button').some(item => item.text() === text)).toBe(false); }
});
it('clears private metadata when an action loses access', async () => {
    server(); const wrapper = await render(); await expand(wrapper);
    server({ failure: { status: 404, body: { message: 'Not Found' } } });
    await button(wrapper, en.storageDownload).trigger('click'); await flushPromises();
    expect(wrapper.text()).not.toContain(copy.identifier); expect(wrapper.text()).not.toContain(version.original_name);
    expect(wrapper.getComponent(DocumentStorage).emitted('failure')[0][0].status).toBe(404);
});

it('keeps physical storage optional and loads only custody data when expanded', async () => {
    const fetch = server(); const wrapper = await render({ mode: 'physical', fixedRevision: 12, revisions: [{ id: 12, identifier: '01', status: 'draft' }] });
    expect(fetch).not.toHaveBeenCalled();
    await expand(wrapper);
    expect(fetch.mock.calls.every(([url]) => url.includes('/copies?') && url.includes('revision_id=12'))).toBe(true);
    expect(wrapper.find('input[type="file"]').exists()).toBe(false);
    expect(wrapper.text()).toContain(en.controlledPhysicalStorage);
    expect(wrapper.text()).toContain('01');
});
it('opens fixed draft attachments immediately, preserves failed replacement inputs and retries safely', async () => {
    const fetch = server(); const wrapper = await render({ mode: 'attachments', embedded: true, fixedRevision: 12 });
    expect(wrapper.getComponent(DocumentStorage).element.tagName).toBe('SECTION');
    expect(fetch.mock.calls.every(([url]) => url.includes('/attachments?') && url.includes('revision_id=12'))).toBe(true);
    await button(wrapper, en.storageReplace).trigger('click');
    const file = new File(['%PDF-test'], 'retry.pdf', { type: 'application/pdf' });
    const input = wrapper.get('input[type="file"]'); Object.defineProperty(input.element, 'files', { value: [file], configurable: true }); await input.trigger('change');
    await wrapper.get('#storage-upload-reason').setValue('Replace draft scan');
    fetch.mockResolvedValueOnce(response({ errors: { file: ['storageInvalidFile'] } }, 422));
    await button(wrapper, en.storageUpload).trigger('click'); await flushPromises();
    expect(wrapper.get('input[type="file"]').element.files[0]).toBe(file);
    expect(wrapper.get('#storage-upload-reason').element.value).toBe('Replace draft scan');
    expect(wrapper.text()).toContain(en.storageInvalidFile);
    await button(wrapper, en.storageUpload).trigger('click'); await flushPromises();
    const uploads = fetch.mock.calls.filter(([, options]) => options.method === 'POST');
    expect(uploads).toHaveLength(2);
    for (const [url, options] of uploads) { expect(url).toContain('revision_id=12'); expect(options.body.get('expected_version')).toBe('1'); expect(options.body.get('attachment_id')).toBe('attachment-a'); }
});
it('retries an initial attachment load failure without exposing custody controls', async () => {
    const fetch = server({ failure: { status: 500, body: {} } }); const wrapper = await render({ mode: 'attachments', fixedRevision: 12 });
    expect(wrapper.text()).toContain(en.controlledRetryLoad);
    fetch.mockImplementation(async () => response({ data: [], writable: true, capabilities: { preview: false, download: false }, max_upload_kb: 25600 }));
    await button(wrapper, en.controlledRetryLoad).trigger('click'); await flushPromises();
    expect(wrapper.text()).toContain(en.storageNoAttachments);
    expect(wrapper.text()).not.toContain(en.storagePhysical);
});
