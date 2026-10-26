import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import ControlledDocuments from '../../resources/js/components/ControlledDocuments.vue';
import LanguageSwitcher from '../../resources/js/components/LanguageSwitcher.vue';
import { createLocalization } from '../../resources/js/localization/index.js';
import en from '../../resources/js/localization/en.js';
import lo from '../../resources/js/localization/lo.js';

const scope = { key: '7:site', company_id: 4, site_id: 7, department_id: null, permissions: ['controlled.drafts.manage', 'controlled.register', 'controlled.release', 'controlled.status.manage', 'numbering.issue'] };
const type = { id: 9, name_en: 'SOP', name_lo: 'ລະບຽບ', category: { id: 8, name_en: 'Procedures', name_lo: 'ຂັ້ນຕອນ' } };
const revision = { id: 11, revision_number: 0, identifier: '00', status: 'draft', title_en: '<script>Safety procedure</script>', title_lo: 'ລະບຽບຄວາມປອດໄພ', owner_id: 5, custodian_id: 5, owner_name: 'Example owner', custodian_name: 'Example owner', issue_date: '2026-10-25', effective_date: '2026-10-26', review_date: '2027-10-26', revision_reason: 'Initial', change_description: 'Initial issue', released_at: null };
const draft = { id: 1, document_code: 'XPPL/SOP/001', type_id: 9, type, category: type.category, internal_number: null, classification: 'normal', registered_at: null, lock_version: 1, status: 'draft', current_revision_id: null, authoritative_revision_id: null, revisions: [revision] };
enableAutoUnmount(afterEach);
beforeEach(() => localStorage.clear());
afterEach(() => { document.body.replaceChildren(); vi.unstubAllGlobals(); });
function response(data, status = 200) { return { ok: status < 400, status, json: async () => structuredClone(data) }; }
function server({ registered = false, released = false, mutationError = null, detailError = null } = {}) {
    let record = structuredClone(draft);
    if (registered || released) { record.registered_at = '2026-10-26T10:00:00Z'; record.internal_number = 'TEST/CD/2026/000001'; record.lock_version = 2; }
    if (released) { record.status = 'current'; record.current_revision_id = record.authoritative_revision_id = 11; record.revisions[0].status = 'current'; record.revisions[0].released_at = '2026-10-26T10:01:00Z'; record.revisions[0].released_by = 5; record.lock_version = 3; }
    const fetch = vi.fn(async (url, options = {}) => {
        const path = new URL(url, 'http://localhost').pathname;
        const method = options.method ?? 'GET';
        if (path.endsWith('/lookups')) { return response({ data: [type], people: [{ id: 5, name: 'Example owner' }] }); }
        if (method === 'GET' && path === '/controlled') { return response({ data: new URL(url, 'http://localhost').searchParams.get('status') === 'current' ? [{ ...record, id: 2, document_code: 'XPPL/SOP/REPLACEMENT' }] : [record], meta: { total: 1 } }); }
        if (method === 'GET') { return detailError ? response(detailError.body, detailError.status) : response({ data: record, audits: [{ id: 1, action: 'created', actor_id: 5, created_at: '2026-10-26T10:00:00Z' }], related: [] }); }
        if (mutationError) { return response(mutationError.body, mutationError.status); }
        const body = JSON.parse(options.body);
        if (path.endsWith('/register')) { record.registered_at = '2026-10-26T10:00:00Z'; record.internal_number = 'TEST/CD/2026/000001'; }
        else if (path.endsWith('/release')) { record.status = 'current'; record.current_revision_id = record.authoritative_revision_id = 11; record.revisions[0].status = 'current'; record.revisions[0].released_at = '2026-10-26T10:01:00Z'; }
        else if (path.endsWith('/revisions')) { record.revisions.push({ ...revision, id: 12, identifier: '01', revision_number: 1, ...body }); }
        else if (path.endsWith('/status')) { record.status = body.status; record.authoritative_revision_id = null; }
        else { Object.assign(record.revisions.at(-1), body); }
        record.lock_version++;
        return response({ data: record }, method === 'POST' && path === '/controlled' ? 201 : 200);
    });
    vi.stubGlobal('fetch', fetch); return fetch;
}
async function render(currentScope = scope) {
    const wrapper = mount({ components: { ControlledDocuments, LanguageSwitcher }, props: { scope: Object }, template: '<div id="dcms-app"><LanguageSwitcher /><ControlledDocuments :scope="scope" endpoint="/controlled" csrf-token="sample-csrf" /></div>' }, { props: { scope: structuredClone(currentScope) }, attachTo: document.body, global: { plugins: [createLocalization()] } });
    await flushPromises(); return wrapper;
}
function button(wrapper, text) { const found = wrapper.findAll('button').find(item => item.text() === text); expect(found, text).toBeDefined(); return found; }
async function show(wrapper) { await button(wrapper, 'Details').trigger('click'); await flushPromises(); }

it('creates drafts with bilingual metadata, exact unit, CSRF and display dates without numbers', async () => {
    const fetch = server(); const wrapper = await render();
    await button(wrapper, en.controlledCreate).trigger('click');
    await wrapper.get('#controlled-document_code').setValue('XPPL/SOP/001');
    await wrapper.get('#controlled-type').setValue(9);
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
    expect(body).toMatchObject({ company_id: 4, site_id: 7, issue_date: '2026-10-25', effective_date: '2026-10-26', title_lo: 'ລະບຽບ' });
    expect(body).not.toHaveProperty('internal_number'); expect(body).not.toHaveProperty('released_at');
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
    expect(wrapper.text()).toContain('Revision 01');
    expect(wrapper.text()).toContain(en.controlledAuthoritative);
    await button(wrapper, en.controlledEdit).trigger('click');
    expect(wrapper.find('#controlled-document_code').exists()).toBe(false);
    expect(wrapper.find('#controlled-classification').exists()).toBe(false);
});
it('renders Lao status, history and dd/mm/yyyy dates without untranslated keys', async () => {
    server({ released: true }); const wrapper = await render(); await show(wrapper);
    await wrapper.get('select[aria-label="Language"]').setValue('lo');
    expect(wrapper.text()).toContain(lo.controlledHistory);
    expect(wrapper.text()).toContain(lo['controlledStatus-current']);
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
