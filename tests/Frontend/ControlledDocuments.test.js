import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import ControlledDocuments from '../../resources/js/components/ControlledDocuments.vue';
import DocumentStorage from '../../resources/js/components/DocumentStorage.vue';
import PdfPreviewDialog from '../../resources/js/components/ui/PdfPreviewDialog.vue';
import ControlledDocumentsPage from '../../resources/js/pages/ControlledDocumentsPage.vue';
import LanguageSwitcher from '../../resources/js/components/LanguageSwitcher.vue';
import { createLocalization } from '../../resources/js/localization/index.js';
import en from '../../resources/js/localization/en.js';
import lo from '../../resources/js/localization/lo.js';

const scope = { key: '7:12', company_id: 4, site_id: 7, department_id: 12, department: { id: 12, name_en: 'Administration', name_lo: 'ບໍລິຫານ' }, permissions: ['controlled.drafts.manage', 'controlled.register', 'controlled.release', 'controlled.status.manage', 'numbering.issue'] };
const type = { id: 9, name_en: 'SOP', name_lo: 'ລະບຽບ', category: { id: 8, name_en: 'Procedures', name_lo: 'ຂັ້ນຕອນ' } };
const revision = { id: 11, revision_number: 0, identifier: 'R00', display_identifier: 'XPPL/SOP/001-R00', status: 'draft', title_en: '<script>Safety procedure</script>', title_lo: 'ລະບຽບຄວາມປອດໄພ', owner_id: 5, custodian_id: 5, owner_name: 'Example owner', custodian_name: 'Example owner', issue_date: '2026-10-25', effective_date: '2026-10-26', review_date: '2027-10-26', revision_reason: 'Initial', change_description: 'Initial issue', released_at: null };
const draft = { id: 1, document_code: 'XPPL/SOP/001', type_id: 9, type, category: type.category, internal_number: null, classification: 'normal', registered_at: null, lock_version: 1, status: 'draft', current_revision_id: null, authoritative_revision_id: null, revisions: [revision] };
enableAutoUnmount(afterEach);
beforeEach(() => localStorage.clear());
afterEach(() => { document.body.replaceChildren(); vi.unstubAllGlobals(); });
function response(data, status = 200) { return { ok: status < 400, status, json: async () => structuredClone(data) }; }
function server({ registered = false, released = false, mutationError = null, detailError = null, approvalEntries = [], reviewEntries = [], xppl = false, contexts = [scope], people = [{ id: 5, name: 'Example owner' }], defaultCustodianId = 5, automatic = null, storage = false, hasAttachment = false, uploadFailures = 0 } = {}) {
    let record = structuredClone(draft);
    if (storage) { record.storage_url = '/storage/controlled/1'; }
    const files = { 11: hasAttachment ? [{ id: 'attachment-r00', versions: [{ id: 'version-r00', version: 1, original_name: 'original-r00.pdf', mime_type: 'application/pdf', size: 100, previewable: true }] }] : [], 12: [] };
    if (xppl) { record.xppl_namespaces = [{ id: 4, prefix: 'XPPL-ADM-SOP' }]; }
    const approvals = structuredClone(approvalEntries);
    const reviews = structuredClone(reviewEntries);
    if (registered || released) { record.registered_at = '2026-10-26T10:00:00Z'; record.internal_number = 'TEST/CD/2026/000001'; record.lock_version = 2; }
    if (released) { record.status = 'current'; record.current_revision_id = record.authoritative_revision_id = 11; record.revisions[0].status = 'current'; record.revisions[0].released_at = '2026-10-26T10:01:00Z'; record.revisions[0].released_by = 5; record.lock_version = 3; }
    const fetch = vi.fn(async (url, options = {}) => {
        const path = new URL(url, 'http://localhost').pathname;
        const method = options.method ?? 'GET';
        if (path.endsWith('/attachments')) {
            const id = Number(new URL(url, 'http://localhost').searchParams.get('revision_id'));
            if (method === 'POST') {
                if (uploadFailures-- > 0) { return response({ message: 'Upload failed' }, 500); }
                const previous = files[id][0];
                const version = { id: `version-${id}-${previous?.versions.length ?? 0}`, version: (previous?.versions.length ?? 0) + 1, original_name: options.body.get('file').name, mime_type: 'application/pdf', size: 100, previewable: true };
                files[id] = [{ id: previous?.id ?? `attachment-${id}`, versions: [...(previous?.versions ?? []), version] }];
                return response({ data: files[id][0] });
            }
            return response({ data: files[id] ?? [], writable: record.revisions.find(item => item.id === id)?.status === 'draft', capabilities: { preview: true, download: true }, max_upload_kb: 25600 });
        }
        if (path.endsWith('/download')) { return { ok: true, status: 200, blob: async () => new Blob(['%PDF-test']), headers: { get: () => null } }; }
        if (path.endsWith('/contexts')) { return response({ data: contexts.map(item => ({ ...item, company: { name_en: 'Example company' }, site: { name_en: 'Example site' } })) }); }
        if (path.endsWith('/lookups')) { return response({ data: [{ ...type, automatic_numbering: automatic }], people, default_custodian_id: defaultCustodianId }); }
        if (method === 'GET' && path === '/controlled') { return response({ data: new URL(url, 'http://localhost').searchParams.get('status') === 'current' ? [{ ...record, id: 2, document_code: 'XPPL/SOP/REPLACEMENT' }] : [record], meta: { total: 1 } }); }
        if (method === 'GET') { return detailError ? response(detailError.body, detailError.status) : response({ data: record, audits: [{ id: 1, action: 'created', actor_id: 5, created_at: '2026-10-26T10:00:00Z' }], related: [], approvals, reviews }); }
        if (mutationError) { return response(mutationError.body, mutationError.status); }
        const body = JSON.parse(options.body);
        if (path.endsWith('/reviews')) { reviews.push({ id: reviews.length + 1, revision_id: 11, actor_id: 5, recorded_at: '2026-10-28T10:00:00Z', next_review_date: '2027-10-28', ...body }); }
        else if (path.endsWith('/approvals')) { approvals.push({ id: approvals.length + 1, revision_id: 11, actor_id: 5, recorded_at: '2026-10-28T10:00:00Z', snapshot: { revision: structuredClone(record.revisions[0]) }, ...body }); }
        else if (path.endsWith('/register')) { record.registered_at = '2026-10-26T10:00:00Z'; record.internal_number = body.namespace_id ? 'XPPL-ADM-SOP-0001' : 'TEST/CD/2026/000001'; if (body.namespace_id) { record.revisions[0].full_identifier = 'XPPL-ADM-SOP-0001-R00'; } }
        else if (path.endsWith('/release')) { record.status = 'current'; record.current_revision_id = record.authoritative_revision_id = 11; record.revisions[0].status = 'current'; record.revisions[0].released_at = '2026-10-26T10:01:00Z'; }
        else if (path.endsWith('/revisions')) { record.revisions.push({ ...revision, id: 12, identifier: 'R01', display_identifier: 'XPPL/SOP/001-R01', revision_number: 1, ...body }); }
        else if (path.endsWith('/status')) { record.status = body.status; record.authoritative_revision_id = null; }
        else { Object.assign(record.revisions.at(-1), body); }
        record.lock_version++;
        return response({ data: record }, method === 'POST' && path === '/controlled' ? 201 : 200);
    });
    vi.stubGlobal('fetch', fetch); return fetch;
}
async function render(currentScope = scope) {
    const wrapper = mount({ components: { ControlledDocuments, LanguageSwitcher }, props: { scope: Object }, template: '<div id="dcms-app"><LanguageSwitcher /><ControlledDocuments :scope="scope" endpoint="/controlled" csrf-token="sample-csrf" /></div>' }, { props: { scope: structuredClone(currentScope) }, attachTo: document.body, global: { plugins: [createLocalization()], stubs: { PdfPreviewDialog: true } } });
    await flushPromises(); return wrapper;
}
function button(wrapper, text) { const found = wrapper.findAll('button').find(item => item.text() === text); expect(found, text).toBeDefined(); return found; }
async function show(wrapper) { await button(wrapper, 'Details').trigger('click'); await flushPromises(); }

it.each([true, false])('previews automatic numbering without manual code entry when configured=%s', async ready => {
    const fetch = server({ automatic: { ready, preview: ready ? 'XPPL-ADM-SOP-0043-R00' : null } });
    const wrapper = await render(); await button(wrapper, en.controlledCreate).trigger('click');
    await wrapper.get('#controlled-type').setValue(9);
    expect(wrapper.find('#controlled-document_code').exists()).toBe(false);
    expect(wrapper.get('#controlled-number-preview').element.value).toBe(ready ? 'XPPL-ADM-SOP-0043-R00' : '');
    expect(wrapper.text()).toContain(en.controlledNumberProvisional);
    if (!ready) { expect(wrapper.text()).toContain(en.controlledAutomaticConfiguration); }
    expect(wrapper.get('#controlled-revision_reason').element.value).toBe('Initial issue');
    expect(wrapper.get('#controlled-change_description').element.value).toBe('First issue of the document');
    await wrapper.get('#controlled-draft-form').trigger('submit'); await flushPromises();
    const body = JSON.parse(fetch.mock.calls.find(([, options]) => options.method === 'POST')[1].body);
    expect(body).not.toHaveProperty('document_code');
    expect(body).not.toHaveProperty('namespace_id');
});

it('explains missing custodian membership and never defaults to an ineligible user', async () => {
    server({ people: [], defaultCustodianId: 5 });
    const wrapper = await render(); await button(wrapper, en.controlledCreate).trigger('click');
    expect(wrapper.get('#controlled-custodian_id').element.value).toBe('');
    expect(wrapper.text()).toContain(en.controlledNoCustodian);
    expect(wrapper.get('#controlled-custodian_id').attributes('required')).toBeDefined();
    await wrapper.get('[data-language-switcher] input[value="lo"]').setValue(true);
    expect(wrapper.text()).toContain(lo.controlledNoCustodian);
});

it('allows draft editors to record resubmission without offering approval authority', async () => {
    const fetch = server(); const wrapper = await render(); await show(wrapper);
    await button(wrapper, en['controlledAction-approvals']).trigger('click');
    expect(wrapper.get('#controlled-approval-decision').findAll('option').map(item => item.element.value)).toEqual(['submitted']);
    await wrapper.get('#controlled-action-form').trigger('submit'); await flushPromises();
    const [url, options] = fetch.mock.calls.find(([url, options]) => options.method === 'POST' && url.includes('/approvals'));
    expect(url).toContain('/controlled/1/revisions/11/approvals');
    expect(options.headers['X-CSRF-TOKEN']).toBe('sample-csrf');
    expect(JSON.parse(options.body)).toMatchObject({ company_id: 4, site_id: 7, lock_version: 1, decision: 'submitted', comments: null });
    expect(wrapper.text()).toContain(en['controlledApproval-submitted']);
    expect(wrapper.text()).toContain(en['controlledConfirm-approvals']);
});

it('records an authorized rejection for the exact revision and safely displays comments', async () => {
    const fetch = server(); const wrapper = await render({ ...scope, permissions: ['controlled.approvals.record'] }); await show(wrapper);
    await button(wrapper, en['controlledAction-approvals']).trigger('click');
    expect(wrapper.get('#controlled-approval-decision').findAll('option').map(item => item.element.value)).toEqual(['approved', 'rejected']);
    await wrapper.get('#controlled-approval-decision').setValue('rejected');
    await wrapper.get('#controlled-approval-comments').setValue('<script>review</script>');
    await wrapper.get('#controlled-action-form').trigger('submit'); await flushPromises();
    expect(JSON.parse(fetch.mock.calls.find(([url, options]) => options.method === 'POST' && url.includes('/approvals'))[1].body)).toMatchObject({ decision: 'rejected', comments: '<script>review</script>' });
    expect(wrapper.text()).toContain('<script>review</script>');
    expect(wrapper.find('script').exists()).toBe(false);
});

it('shows revision-specific history to readers without mutation controls', async () => {
    server({ approvalEntries: [{ id: 1, revision_id: 11, actor_id: 5, decision: 'approved', comments: 'Reviewed content', recorded_at: '2026-10-28T10:00:00Z' }, { id: 2, revision_id: 999, actor_id: 5, decision: 'rejected', comments: 'Other revision', recorded_at: '2026-10-28T10:00:00Z' }] });
    const wrapper = await render({ ...scope, permissions: [] }); await show(wrapper);
    expect(wrapper.text()).toContain('Reviewed content');
    expect(wrapper.text()).not.toContain('Other revision');
    expect(wrapper.findAll('button').some(item => item.text() === en['controlledAction-approvals'])).toBe(false);
});

it('creates drafts with bilingual metadata, exact unit, CSRF and display dates without numbers', async () => {
    const fetch = server(); const wrapper = await render();
    await button(wrapper, en.controlledCreate).trigger('click');
    expect(wrapper.get('#controlled-department').element.value).toBe(scope.key);
    expect(wrapper.get('#controlled-department option').text()).toBe('Administration');
    expect(wrapper.findAll('#controlled-department option')).toHaveLength(1);
    expect(wrapper.get('#controlled-department').attributes('readonly')).toBeUndefined();
    expect(wrapper.get('#controlled-owner_id').attributes('required')).toBeUndefined();
    expect(wrapper.get('#controlled-owner_id').element.value).toBe('');
    expect(wrapper.get('#controlled-custodian_id').element.value).toBe('5');
    expect(wrapper.get('#controlled-type option[value="9"]').text()).toBe('SOP');
    await wrapper.get('#controlled-document_code').setValue('XPPL/SOP/001');
    await wrapper.get('#controlled-type').setValue(9);
    expect(wrapper.get('#controlled-category').element.value).toBe('Procedures');
    expect(wrapper.get('#controlled-category').attributes('readonly')).toBeDefined();
    await wrapper.get('#controlled-title_en').setValue('Safety procedure');
    await wrapper.get('#controlled-title_lo').setValue('ລະບຽບ');
    await wrapper.get('#controlled-owner_id').setValue(5);
    await wrapper.get('#controlled-custodian_id').setValue(5);
    await wrapper.get('#controlled-issue_date').setValue('25/10/2026');
    await wrapper.get('#controlled-effective_date').setValue('26/10/2026');
    await wrapper.get('#controlled-revision_reason').setValue('Initial');
    await wrapper.get('#controlled-change_description').setValue('Initial issue');
    await wrapper.get('#controlled-draft-form').trigger('submit'); await flushPromises();
    const options = fetch.mock.calls.find(([, options]) => options.method === 'POST')[1];
    const body = JSON.parse(options.body);
    expect(options.headers['X-CSRF-TOKEN']).toBe('sample-csrf');
    expect(body).toMatchObject({ company_id: 4, site_id: 7, department_id: 12, type_id: 9, issue_date: '2026-10-25', effective_date: '2026-10-26', title_lo: 'ລະບຽບ' });
    expect(body).not.toHaveProperty('internal_number'); expect(body).not.toHaveProperty('released_at');
});
it('submits an unassigned owner as null and preserves an existing draft custodian when editing', async () => {
    const fetch = server();
    const wrapper = await render(); await button(wrapper, en.controlledCreate).trigger('click');
    await wrapper.get('#controlled-draft-form').trigger('submit'); await flushPromises();
    const body = JSON.parse(fetch.mock.calls.find(([, options]) => options.method === 'POST')[1].body);
    expect(body.owner_id).toBeNull();
    expect(body.custodian_id).toBe(5);
    await button(wrapper, en.controlledEdit).trigger('click');
    expect(wrapper.get('#controlled-custodian_id').element.value).toBe('5');
});
it('confirms registration independently of release and sends the version being reviewed', async () => {
    const fetch = server(); const wrapper = await render(); await show(wrapper);
    await button(wrapper, en['controlledAction-register']).trigger('click');
    expect(fetch.mock.calls.filter(([, options]) => options.method === 'POST')).toHaveLength(0);
    await wrapper.get('#controlled-action-form').trigger('submit'); await flushPromises();
    const body = JSON.parse(fetch.mock.calls.find(([, options]) => options.method === 'POST')[1].body);
    expect(body).toMatchObject({ lock_version: 1 });
    expect(wrapper.text()).toContain('TEST/CD/2026/000001');
    expect(wrapper.text()).toContain(en.controlledNoAuthority);
});
it('releases the reviewed draft with a reason and hides direct editing of released metadata', async () => {
    const fetch = server({ registered: true }); const wrapper = await render(); await show(wrapper);
    expect(wrapper.find('script').exists()).toBe(false);
    await button(wrapper, en['controlledAction-release']).trigger('click');
    await wrapper.get('#controlled-action-reason').setValue('Authorized issue');
    await wrapper.get('#controlled-action-form').trigger('submit'); await flushPromises();
    const body = JSON.parse(fetch.mock.calls.find(([, options]) => options.method === 'POST')[1].body);
    expect(body).toMatchObject({ lock_version: 2, revision_id: 11, reason: 'Authorized issue' });
    expect(wrapper.findAll('button').some(item => item.text() === en.controlledEdit)).toBe(false);
    expect(wrapper.text()).toContain(en['controlledAction-revisions']);
    expect(wrapper.text()).not.toContain(en.controlledFilesDeferred);
});
it('prepares the next revision without resubmitting released metadata and preserves current authority', async () => {
    const fetch = server({ released: true }); const wrapper = await render(); await show(wrapper);
    await button(wrapper, en['controlledAction-revisions']).trigger('click');
    await wrapper.get('#controlled-action-revision_reason').setValue('Annual review');
    await wrapper.get('#controlled-action-change_description').setValue('Updated precautions');
    await wrapper.get('#controlled-action-form').trigger('submit'); await flushPromises();
    const body = JSON.parse(fetch.mock.calls.find(([, options]) => options.method === 'POST')[1].body);
    expect(body).toMatchObject({ lock_version: 3, revision_reason: 'Annual review', change_description: 'Updated precautions' });
    expect(body).not.toHaveProperty('title_en');
    expect(wrapper.text()).toContain('Revision R01');
    expect(wrapper.get('#controlled-detail-title').text()).toBe('XPPL/SOP/001');
    expect(wrapper.text()).toContain(`${en.controlledRevisionIdentifier}: XPPL/SOP/001-R01`);
    expect(wrapper.text()).toContain(en.controlledAuthoritative);
    await button(wrapper, en.controlledEdit).trigger('click');
    expect(wrapper.find('#controlled-document_code').exists()).toBe(false);
    expect(wrapper.find('#controlled-classification').exists()).toBe(false);
});
it('preserves ambiguous stored codes and displays the revision label separately', async () => {
    const fetch = server(); const wrapper = await render();
    fetch.mockImplementationOnce(async () => response({ data: { ...structuredClone(draft), document_code: 'LEGACY001R00', revisions: [{ ...revision, display_identifier: null }] }, audits: [], related: [], approvals: [] }));
    await show(wrapper);
    expect(wrapper.get('#controlled-detail-title').text()).toBe('LEGACY001R00');
    expect(wrapper.text()).toContain('Revision R00');
    expect(wrapper.text()).not.toContain(en.controlledRevisionIdentifier);
    expect(wrapper.text()).not.toContain('LEGACY001R00-R00');
});
it('renders Lao status, history and dd/mm/yyyy dates without untranslated keys', async () => {
    server({ released: true }); const wrapper = await render(); await show(wrapper);
    await wrapper.get('[data-language-switcher] input[value="lo"]').setValue(true);
    expect(wrapper.text()).toContain(lo.controlledHistory);
    expect(wrapper.text()).toContain(lo['controlledStatus-current']);
    expect(wrapper.text()).toContain(lo.controlledOwnerDepartment); expect(wrapper.text()).toContain(lo.documentCategory);
    expect(wrapper.text()).toContain('ລະບຽບຄວາມປອດໄພ');
    expect(wrapper.text()).toContain('26/10/2026');
    expect(wrapper.text()).not.toMatch(/controlled[A-Z-]/);
});
it('hides mutation actions for readers and removes sensitive detail after access revocation', async () => {
    server({ registered: true }); const wrapper = await render({ ...scope, permissions: [] }); await show(wrapper);
    expect(wrapper.findAll('button').some(item => item.text() === en['controlledAction-release'])).toBe(false);
    server({ detailError: { status: 404, body: { message: 'Not Found' } } });
    await button(wrapper, en.backToRegister).trigger('click'); await flushPromises();
    await show(wrapper);
    expect(wrapper.text()).not.toContain(en.controlledHistory);
    expect(wrapper.text()).not.toContain('Example owner');
});
it('keeps the editor and field errors on validation failure', async () => {
    server({ mutationError: { status: 422, body: { errors: { title_en: ['controlledValidation'] } } } });
    const wrapper = await render(); await button(wrapper, en.controlledCreate).trigger('click');
    await wrapper.get('#controlled-draft-form').trigger('submit'); await flushPromises();
    expect(wrapper.get('#controlled-title_en').attributes('aria-invalid')).toBe('true');
    expect(wrapper.text()).toContain(en.controlledValidation);
});

it('finds an accessible current replacement and submits a reasoned supersession with the reviewed version', async () => {
    const fetch = server({ released: true }); const wrapper = await render(); await show(wrapper);
    await button(wrapper, en['controlledAction-status']).trigger('click');
    await wrapper.get('#controlled-next-status').setValue('superseded');
    await wrapper.get('#controlled-replacement-search').setValue('REPLACEMENT');
    await button(wrapper, en.controlledFindReplacement).trigger('click'); await flushPromises();
    expect(fetch.mock.calls.some(([url]) => url.includes('status=current') && url.includes('search=REPLACEMENT'))).toBe(true);
    await wrapper.get('#controlled-replacement').setValue(2);
    await wrapper.get('#controlled-action-reason').setValue('Consolidated procedure');
    await wrapper.get('#controlled-action-form').trigger('submit'); await flushPromises();
    const body = JSON.parse(fetch.mock.calls.find(([, options]) => options.method === 'POST')[1].body);
    expect(body).toMatchObject({ lock_version: 3, status: 'superseded', replaced_by_id: 2, reason: 'Consolidated procedure' });
    expect(wrapper.text()).toContain(en.controlledNoAuthority);
    expect(wrapper.findAll('button').some(item => item.text() === en['controlledAction-revisions'])).toBe(false);
});

it('opens the existing controlled detail directly from a document search link', async () => {
    window.history.replaceState({}, '', '/controlled-documents?unit=7:site&record=1');
    try {
        server({ released: true });
        const wrapper = await render();
        expect(wrapper.get('#controlled-detail-title').text()).toBe('XPPL/SOP/001');
        expect(wrapper.text()).toContain(en.controlledAuthoritative);
    } finally {
        window.history.replaceState({}, '', '/');
    }
});

it('selects an approved XPPL mapping explicitly and displays the full revision identifier', async () => {
    const fetch = server({ xppl: true }); const wrapper = await render(); await show(wrapper);
    const label = wrapper.findAll('label').find(item => item.text().trim() === en.numberingSelection);
    const select = wrapper.get(`#${label.attributes('for')}`);
    expect(select.element.value).toBe('');
    await select.setValue(4);
    await button(wrapper, en['controlledAction-register']).trigger('click');
    await wrapper.get('#controlled-action-form').trigger('submit'); await flushPromises();
    const body = JSON.parse(fetch.mock.calls.find(([, options]) => options.method === 'POST')[1].body);
    expect(body).toMatchObject({ namespace_id: 4, lock_version: 1 });
    expect(wrapper.get('#controlled-detail-title').text()).toBe('XPPL-ADM-SOP-0001-R00');
});

it('selects only authorized owner departments inside a fresh form and clears stale fields on change', async () => {
    const engineering = { ...scope, key: '7:13', department_id: 13, department: { id: 13, name_en: 'Engineering', name_lo: 'ວິສະວະກຳ' } };
    const siteWide = { ...scope, key: '7:site', department_id: null, department: null };
    localStorage.setItem('dcms.workspace.standalone', JSON.stringify({ site: '4:7', unit: scope.key }));
    const fetch = server({ contexts: [scope, engineering, siteWide] });
    const wrapper = mount(ControlledDocumentsPage, { props: { endpoints: { endpoint: '/controlled', contexts: '/controlled/contexts' }, csrfToken: 'sample-csrf' }, global: { plugins: [createLocalization()] } });
    await flushPromises();

    expect(wrapper.get('label[for="controlled-context"]').text()).toBe(en.controlledOwnerDepartment);
    expect(wrapper.findAll('#controlled-context option').map(option => option.text())).toEqual(['Administration', 'Engineering', en.siteWideDepartment]);
    await button(wrapper, en.controlledCreate).trigger('click');
    expect(wrapper.find('#controlled-context').exists()).toBe(false);
    expect(wrapper.findAll('#controlled-department option').map(option => option.text())).toEqual(['Administration', 'Engineering', en.siteWideDepartment]);
    await wrapper.get('#controlled-title_en').setValue('Safe entered value');
    await wrapper.get('#controlled-type').setValue(9);
    const formElement = wrapper.get('#controlled-draft-form').element;
    let finishOldLookup;
    fetch.mockImplementationOnce((url, options) => new Promise(resolve => {
        finishOldLookup = () => resolve(response({ data: [{ ...type, id: 99 }], people: [] }));
    }));
    await wrapper.get('#controlled-department').setValue('7:13'); await flushPromises();

    expect(wrapper.get('#controlled-department').element.value).toBe('7:13');
    expect(wrapper.get('#controlled-title_en').element.value).toBe('Safe entered value');
    expect(wrapper.get('#controlled-draft-form').element).toBe(formElement);
    expect(wrapper.get('#controlled-type').element.value).toBe('');
    expect(wrapper.get('#controlled-draft-form > p[role="status"]').text()).toBe(en.documentDepartmentLoading);
    expect(wrapper.get('#controlled-draft-form button[type="submit"]').element.disabled).toBe(true);
    await wrapper.get('#controlled-draft-form').trigger('submit');
    expect(fetch.mock.calls.some(([, options]) => options.method === 'POST')).toBe(false);
    expect(fetch.mock.calls.some(([url]) => url.includes('/controlled/lookups?') && url.includes('department_id=13'))).toBe(true);

    await wrapper.get('#controlled-department').setValue('7:site'); await flushPromises();
    finishOldLookup(); await flushPromises();
    expect(wrapper.find('#controlled-type option[value="99"]').exists()).toBe(false);
    expect(wrapper.get('#controlled-draft-form').element).toBe(formElement);
    expect(wrapper.get('#controlled-draft-form button[type="submit"]').element.disabled).toBe(false);
    fetch.mockImplementationOnce(async () => response({}, 500));
    await wrapper.get('#controlled-department').setValue('7:13'); await flushPromises();
    expect(wrapper.get('#controlled-draft-form').element).toBe(formElement);
    expect(wrapper.get('[role="alert"]').text()).toContain(en.rbacRequestFailed);
    expect(wrapper.get('#controlled-draft-form button[type="submit"]').element.disabled).toBe(true);
    await wrapper.get('#controlled-draft-form').trigger('submit');
    expect(fetch.mock.calls.some(([, options]) => options.method === 'POST')).toBe(false);
    expect(wrapper.get('#controlled-department').element.value).toBe('7:13');
    expect(fetch.mock.calls.some(([url]) => {
        const requestUrl = new URL(url, 'http://localhost');
        return requestUrl.pathname === '/controlled/lookups' && requestUrl.searchParams.get('site_id') === '7' && !requestUrl.searchParams.has('department_id');
    })).toBe(true);
});

const attachmentScope = { ...scope, permissions: [...scope.permissions, 'documents.attachments.manage'] };
async function chooseFile(wrapper, selector, name = 'draft.pdf') {
    const input = wrapper.get(selector);
    const file = new File(['%PDF-test'], name, { type: 'application/pdf' });
    Object.defineProperty(input.element, 'files', { value: [file], configurable: true });
    await input.trigger('change');
    return file;
}
it.each([false, true])('saves a new draft with an optional attachment=%s through the authorized APIs', async withFile => {
    const fetch = server({ storage: true }); const wrapper = await render(attachmentScope);
    await button(wrapper, en.controlledCreate).trigger('click');
    expect(wrapper.text()).toContain(en.controlledAttachment);
    expect(wrapper.get('#controlled-attachment-file').attributes('required')).toBeUndefined();
    if (withFile) { await chooseFile(wrapper, '#controlled-attachment-file'); }
    await wrapper.get('#controlled-draft-form').trigger('submit'); await flushPromises();
    const uploads = fetch.mock.calls.filter(([url, options]) => options.method === 'POST' && url.includes('/attachments?'));
    expect(uploads).toHaveLength(withFile ? 1 : 0);
    if (withFile) {
        expect(uploads[0][0]).toContain('revision_id=11');
        expect(uploads[0][1].body.get('file').name).toBe('draft.pdf');
        expect(uploads[0][1].headers['X-CSRF-TOKEN']).toBe('sample-csrf');
        expect(uploads[0][1].headers).not.toHaveProperty('Content-Type');
        expect(wrapper.text()).toContain('draft.pdf');
    }
    expect(fetch.mock.calls.filter(([url, options]) => options.method === 'POST' && url.startsWith('/controlled?'))).toHaveLength(1);
    expect(wrapper.find('#controlled-draft-form').exists()).toBe(false);
    expect(wrapper.find('#storage-revision').exists()).toBe(false);
    expect(wrapper.getComponent(DocumentStorage).element.tagName).toBe('SECTION');
    expect(wrapper.findAllComponents(DocumentStorage)).toHaveLength(2);
    expect(fetch.mock.calls.some(([url]) => url.includes('/copies?'))).toBe(false);
});
it('retains the saved draft identity and file on upload failure and retries only the attachment', async () => {
    const fetch = server({ storage: true, uploadFailures: 1 }); const wrapper = await render(attachmentScope);
    await button(wrapper, en.controlledCreate).trigger('click');
    await wrapper.get('#controlled-title_en').setValue('Preserved document');
    const file = await chooseFile(wrapper, '#controlled-attachment-file');
    await wrapper.get('#controlled-draft-form').trigger('submit'); await flushPromises();
    expect(wrapper.text()).toContain(en.controlledDraftSavedUploadPending);
    expect(wrapper.text()).toContain(en.controlledAttachmentFailed);
    expect(wrapper.get('#controlled-title_en').element.value).toBe('Preserved document');
    expect(wrapper.get('#controlled-attachment-file').element.files[0]).toBe(file);
    await wrapper.get('#controlled-draft-form').trigger('submit'); await flushPromises();
    expect(fetch.mock.calls.filter(([url, options]) => options.method === 'POST' && url.startsWith('/controlled?'))).toHaveLength(1);
    expect(fetch.mock.calls.filter(([url, options]) => options.method === 'POST' && url.includes('/attachments?'))).toHaveLength(2);
    expect(fetch.mock.calls.some(([, options]) => options.method === 'PATCH')).toBe(false);
    expect(wrapper.find('#controlled-draft-form').exists()).toBe(false);
    expect(wrapper.text()).toContain('draft.pdf');
});
it('preserves metadata and the selected file when draft validation fails', async () => {
    const fetch = server({ storage: true, mutationError: { status: 422, body: { errors: { title_en: ['controlledValidation'] } } } });
    const wrapper = await render(attachmentScope); await button(wrapper, en.controlledCreate).trigger('click');
    const file = await chooseFile(wrapper, '#controlled-attachment-file');
    await wrapper.get('#controlled-title_en').setValue('Keep this title');
    await wrapper.get('#controlled-draft-form').trigger('submit'); await flushPromises();
    expect(wrapper.get('#controlled-title_en').element.value).toBe('Keep this title');
    expect(wrapper.get('#controlled-attachment-file').element.files[0]).toBe(file);
    expect(fetch.mock.calls.some(([url, options]) => url.includes('/attachments?') && options.method === 'POST')).toBe(false);
});
it('shows and replaces the draft attachment inside the editor without nested forms or revision selection', async () => {
    const fetch = server({ storage: true, hasAttachment: true }); const wrapper = await render(attachmentScope); await show(wrapper);
    await button(wrapper, en.controlledEdit).trigger('click'); await flushPromises();
    expect(wrapper.text()).toContain('original-r00.pdf');
    expect(wrapper.find('#storage-revision').exists()).toBe(false);
    await button(wrapper, en.storagePreview).trigger('click'); await flushPromises();
    expect(wrapper.findComponent(PdfPreviewDialog).props('url')).toContain('revision_id=11');
    await button(wrapper, en.storageReplace).trigger('click');
    await chooseFile(wrapper, '#storage-upload-form input[type="file"]', 'replacement.pdf');
    await wrapper.get('#storage-upload-reason').setValue('Corrected scan');
    expect(wrapper.find('form form').exists()).toBe(false);
    await button(wrapper, en.storageUpload).trigger('click'); await flushPromises();
    const upload = fetch.mock.calls.find(([url, options]) => options.method === 'POST' && url.includes('/attachments?'));
    expect(upload[0]).toContain('revision_id=11');
    expect(upload[1].body.get('attachment_id')).toBe('attachment-r00');
    expect(upload[1].body.get('expected_version')).toBe('1');
    expect(wrapper.text()).toContain('replacement.pdf');
    expect(wrapper.find('#controlled-draft-form').exists()).toBe(true);
    expect(fetch.mock.calls.some(([, options]) => options.method === 'PATCH')).toBe(false);
});
it('automatically targets draft R01 while keeping the released R00 attachment read-only', async () => {
    const fetch = server({ storage: true, hasAttachment: true, released: true }); const wrapper = await render(attachmentScope); await show(wrapper);
    await button(wrapper, en['controlledAction-revisions']).trigger('click');
    await wrapper.get('#controlled-action-revision_reason').setValue('Update file');
    await wrapper.get('#controlled-action-change_description').setValue('New edition');
    await wrapper.get('#controlled-action-form').trigger('submit'); await flushPromises();
    expect(wrapper.text()).toContain(en.controlledDraftAttachment.replace('{revision}', 'R01'));
    expect(wrapper.text()).toContain(en.controlledReleasedAttachment.replace('{revision}', 'R00'));
    const attachments = wrapper.findAllComponents(DocumentStorage).find(item => item.props('mode') === 'attachments');
    expect(attachments.props('fixedRevision')).toBe(12);
    await button(wrapper, en.controlledReleasedAttachment.replace('{revision}', 'R00')).trigger('click'); await flushPromises();
    expect(wrapper.text()).toContain('original-r00.pdf');
    expect(wrapper.findAll('button').some(item => item.text() === en.storageReplace)).toBe(false);
    await button(wrapper, en.controlledEdit).trigger('click'); await flushPromises();
    expect(wrapper.findAllComponents(DocumentStorage)[0].props('fixedRevision')).toBe(12);
    expect(wrapper.text()).toContain(en.controlledReleasedAttachment.replace('{revision}', 'R00'));
    expect(wrapper.text()).toContain(en.controlledDraftFileHelp);
    await button(wrapper, en.storageUpload).trigger('click');
    await chooseFile(wrapper, '#storage-upload-form input[type="file"]', 'r01.pdf');
    await wrapper.get('#storage-upload-reason').setValue('New revision file');
    await button(wrapper, en.storageUpload).trigger('click'); await flushPromises();
    const upload = fetch.mock.calls.find(([url, options]) => options.method === 'POST' && url.includes('/attachments?'));
    expect(upload[0]).toContain('revision_id=12');
    expect(upload[1].body.has('attachment_id')).toBe(false);
    await wrapper.get('[data-language-switcher] input[value="lo"]').setValue(true);
    expect(wrapper.text()).toContain(lo.controlledAttachment);
    expect(wrapper.text()).not.toContain('controlledDraftAttachment');
});
it('does not offer file selection without attachment permission', async () => {
    server({ storage: true }); const wrapper = await render(); await button(wrapper, en.controlledCreate).trigger('click');
    expect(wrapper.find('input[type="file"]').exists()).toBe(false);
    expect(wrapper.text()).toContain(en.controlledAttachmentPermission);
});

it('allows continuing with the saved draft when an optional upload fails', async () => {
    const fetch = server({ storage: true, uploadFailures: 1 }); const wrapper = await render(attachmentScope);
    await button(wrapper, en.controlledCreate).trigger('click'); await chooseFile(wrapper, '#controlled-attachment-file');
    await wrapper.get('#controlled-draft-form').trigger('submit'); await flushPromises();
    await button(wrapper, en.controlledContinueWithoutAttachment).trigger('click'); await flushPromises();
    expect(wrapper.find('#controlled-draft-form').exists()).toBe(false);
    expect(wrapper.text()).toContain(en.storageNoAttachments);
    expect(fetch.mock.calls.filter(([url, options]) => options.method === 'POST' && url.startsWith('/controlled?'))).toHaveLength(1);
});

it('records completion on the authoritative revision with CSRF and displays escaped review history', async () => {
    const fetch = server({ released: true });
    const wrapper = await render({ ...scope, permissions: ['controlled.reviews.record'] }); await show(wrapper);
    await button(wrapper, en['controlledAction-reviews']).trigger('click');
    await wrapper.get('#controlled-review-outcome').setValue('revision_required');
    await wrapper.get('#controlled-review-comments').setValue('<script>Check procedure</script>');
    await wrapper.get('#controlled-action-form').trigger('submit'); await flushPromises();
    const [url, options] = fetch.mock.calls.find(([url, options]) => options.method === 'POST' && url.includes('/reviews'));
    expect(url).toContain('/controlled/1/revisions/11/reviews');
    expect(options.headers['X-CSRF-TOKEN']).toBe('sample-csrf');
    expect(JSON.parse(options.body)).toEqual({ company_id: 4, site_id: 7, department_id: 12, lock_version: 3, outcome: 'revision_required', comments: '<script>Check procedure</script>' });
    expect(wrapper.text()).toContain(en['controlledReview-revision_required']);
    expect(wrapper.text()).toContain('<script>Check procedure</script>');
    expect(wrapper.find('script').exists()).toBe(false);
    expect(wrapper.text()).toContain('28/10/2027');
});

it('shows reviews only in their revision history and hides completion controls from readers and drafts', async () => {
    server({ released: true, reviewEntries: [{ id: 1, revision_id: 11, actor_id: 5, outcome: 'no_changes_required', comments: 'Completed check', recorded_at: '2026-10-28T10:00:00Z', next_review_date: '2027-10-28' }, { id: 2, revision_id: 999, outcome: 'revision_required', comments: 'Foreign history' }] });
    const wrapper = await render({ ...scope, permissions: [] }); await show(wrapper);
    expect(wrapper.text()).toContain('Completed check');
    expect(wrapper.text()).not.toContain('Foreign history');
    expect(wrapper.findAll('button').some(item => item.text() === en['controlledAction-reviews'])).toBe(false);
    server();
    const draftWrapper = await render({ ...scope, permissions: ['controlled.reviews.record'] }); await show(draftWrapper);
    expect(draftWrapper.findAll('button').some(item => item.text() === en['controlledAction-reviews'])).toBe(false);
});
