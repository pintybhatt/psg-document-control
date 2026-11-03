import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import OutgoingCorrespondence from '../../resources/js/components/OutgoingCorrespondence.vue';
import OutgoingPage from '../../resources/js/pages/OutgoingPage.vue';
import LanguageSwitcher from '../../resources/js/components/LanguageSwitcher.vue';
import { createLocalization } from '../../resources/js/localization/index.js';
import en from '../../resources/js/localization/en.js';
import lo from '../../resources/js/localization/lo.js';

const scope = { key: '7:12', company_id: 4, site_id: 7, department_id: 12, department: { id: 12, name_en: 'Engineering', name_lo: 'ວິສະວະກຳ' }, permissions: ['outgoing.drafts.manage', 'outgoing.register', 'numbering.issue'] };
const type = { id: 9, code: 'LTR', name_en: 'Letter', name_lo: 'ຈົດໝາຍ', category: { id: 8, name_en: 'Correspondence', name_lo: 'ເອກະສານ' } };
const draft = { id: 1, type_id: 9, type, category: type.category, subject: '<script>Example subject</script>', signatory_name: 'Example signatory', signatory_title: 'Example title', document_date: '2026-10-26', priority: 'normal', classification: 'normal', status: 'draft', internal_number: null, official_number: null, recipients: [{ id: 11, delivery: null, kind: 'primary', organization: 'Example recipient', email: 'recipient@example.test' }, { id: 12, delivery: null, kind: 'copy', organization: 'Copy recipient' }] };
const descriptors = Object.getOwnPropertyDescriptors(HTMLDialogElement.prototype);
enableAutoUnmount(afterEach);
beforeEach(() => localStorage.clear());
afterEach(() => { document.body.replaceChildren(); vi.unstubAllGlobals(); });
beforeAll(() => Object.defineProperties(HTMLDialogElement.prototype, {
    showModal: { configurable: true, value() { this.setAttribute('open', ''); } },
    close: { configurable: true, value() { if (this.open) { this.removeAttribute('open'); this.dispatchEvent(new Event('close')); } } },
}));
afterAll(() => {
    for (const name of ['showModal', 'close']) {
        if (descriptors[name]) { Object.defineProperty(HTMLDialogElement.prototype, name, descriptors[name]); }
        else { delete HTMLDialogElement.prototype[name]; }
    }
});
function response(data, status = 200) { return { ok: status < 400, status, json: async () => structuredClone(data) }; }
function server({ mutationError = null, detailError = null, registered = false, xppl = false, contexts = [scope], automatic = false, legacyType = false, availableTypes = [type] } = {}) {
    let record = { ...structuredClone(draft), ...(registered ? { status: 'registered', operations: { status: 'dispatched', revision: 1 } } : {}) };
    if (legacyType) { record.type_id = 10; record.type = { ...type, id: 10, code: 'LOT', name_en: 'Letter Outgoing' }; }
    if (xppl) { record.xppl_namespaces = [{ id: 4, prefix: 'XPPL-ENG-LTR' }]; }
    const fetch = vi.fn(async (url, options = {}) => {
        const path = new URL(url, 'http://localhost').pathname;
        const method = options.method ?? 'GET';
        if (path.endsWith('/contexts')) { return response({ data: contexts.map(item => ({ ...item, company: { name_en: 'Example company' }, site: { name_en: 'Example site' } })) }); }
        if (path.endsWith('/lookups')) { return response({ data: availableTypes, automatic_numbering: automatic }); }
        if (method === 'GET' && path === '/outgoing') { return response({ data: [record], meta: { total: 1 } }); }
        if (method === 'GET') { return detailError ? response(detailError.body, detailError.status) : response({ data: record, audits: [{ id: 1, action: 'created', created_at: '2026-10-26', actor_id: 5 }] }); }
        if (mutationError) { return response(mutationError.body, mutationError.status); }
        const body = JSON.parse(options.body);
        record = path.endsWith('/register') ? { ...record, status: 'registered', internal_number: 'TEST/OUT/2026/000001', official_number: body.namespace_id ? 'XPPL-ENG-LTR-26-0001' : 'TEST/OFFICIAL/2026/000001' } : { ...record, ...body };
        if (body.type_id) { record.type = availableTypes.find(item => String(item.id) === String(body.type_id)) ?? record.type; }
        return response({ data: record }, method === 'POST' && path === '/outgoing' ? 201 : 200);
    });
    vi.stubGlobal('fetch', fetch); return fetch;
}
async function render(currentScope = scope) {
    const wrapper = mount({ components: { OutgoingCorrespondence, LanguageSwitcher }, props: { scope: Object }, template: '<div id="dcms-app"><LanguageSwitcher /><OutgoingCorrespondence :scope="scope" endpoint="/outgoing" csrf-token="sample-csrf" /></div>' }, { props: { scope: structuredClone(currentScope) }, attachTo: document.body, global: { plugins: [createLocalization()] } });
    await flushPromises(); return wrapper;
}
function button(wrapper, text) { const found = wrapper.findAll('button').find(item => item.text() === text); expect(found, text).toBeDefined(); return found; }
async function show(wrapper) { await button(wrapper, 'Details').trigger('click'); await flushPromises(); }

describe('outgoing correspondence', () => {
    it('defaults a new official draft to the approved type with a friendly bilingual label', async () => {
        server({ automatic: true });
        const wrapper = await render();
        await button(wrapper, en.outgoingCreate).trigger('click');
        expect(wrapper.get('#outgoing-type').element.value).toBe('9');
        expect(wrapper.get('#outgoing-type option[value="9"]').text()).toBe(en.outgoingOfficialLetter);
        expect(wrapper.get('#outgoing-category').element.value).toBe('Correspondence');
        await wrapper.get('select[aria-label="Language"]').setValue('lo');
        expect(wrapper.get('#outgoing-type option[value="9"]').text()).toBe(lo.outgoingOfficialLetter);
    });
    it('corrects a legacy draft only through an explicit type selection and save', async () => {
        const fetch = server({ automatic: true, legacyType: true });
        const wrapper = await render(); await show(wrapper);
        expect(wrapper.text()).toContain(en.outgoingCorrectDraftType);
        expect(button(wrapper, en.outgoingRegister).element.disabled).toBe(true);
        await button(wrapper, en.outgoingEdit).trigger('click');
        expect(wrapper.get('#outgoing-type').element.value).toBe('10');
        expect(wrapper.get('#outgoing-type option[value="10"]').element.disabled).toBe(true);
        expect(fetch.mock.calls.some(([, options]) => options.method === 'PATCH')).toBe(false);
        await wrapper.get('#outgoing-type').setValue(9);
        expect(wrapper.get('#outgoing-subject').element.value).toBe(draft.subject);
        expect(wrapper.get('#outgoing-recipient-0-organization').element.value).toBe('Example recipient');
        await wrapper.get('#outgoing-draft-form').trigger('submit'); await flushPromises();
        const body = JSON.parse(fetch.mock.calls.find(([, options]) => options.method === 'PATCH')[1].body);
        expect(body.type_id).toBe(9);
        expect(body.subject).toBe(draft.subject);
        expect(body.recipients).toHaveLength(2);
        expect(wrapper.text()).toContain(en.outgoingOfficialLetter);
        expect(wrapper.text()).not.toContain(en.outgoingCorrectDraftType);
        expect(button(wrapper, en.outgoingRegister).element.disabled).toBe(false);
    });
    it('explains missing master-data applicability instead of inventing an official type', async () => {
        server({ automatic: true, availableTypes: [] });
        const wrapper = await render(); await button(wrapper, en.outgoingCreate).trigger('click');
        expect(wrapper.get('#outgoing-type').element.value).toBe('');
        expect(wrapper.findAll('#outgoing-type option')).toHaveLength(1);
        expect(wrapper.text()).toContain(en.outgoingOfficialTypeMissing);
    });
    it('uses the activated official policy without offering a namespace override', async () => {
        const fetch = server({ xppl: true, automatic: true });
        const wrapper = await render(); await show(wrapper);
        expect(wrapper.findAll('label').some(item => item.text() === en.numberingSelection)).toBe(false);
        await button(wrapper, 'Register and assign numbers').trigger('click'); await flushPromises();
        await button(wrapper.get('dialog'), 'Confirm').trigger('click'); await flushPromises();
        const [, options] = fetch.mock.calls.find(([url]) => url.includes('/register?'));
        expect(JSON.parse(options.body)).not.toHaveProperty('namespace_id');
    });
    it('uses the operational status in the register and opens dispatch handling from details', async () => {
        server({ registered: true });
        const wrapper = await render({ ...scope, permissions: [...scope.permissions, 'outgoing.operations.manage'] });
        expect(wrapper.text()).toContain('Dispatched');
        await show(wrapper);
        expect(wrapper.text()).toContain('Dispatch and delivery');
        expect(wrapper.findAll('button').some(item => item.text() === 'Delivery / acknowledgment')).toBe(true);
    });
    it('creates a draft with multiple primary and copy recipients, CSRF and no numbers', async () => {
        const fetch = server(); const wrapper = await render();
        await button(wrapper, 'Create outgoing draft').trigger('click');
        expect(wrapper.get('#outgoing-department').element.value).toBe(scope.key);
        expect(wrapper.get('#outgoing-department option').text()).toBe('Engineering');
        expect(wrapper.findAll('#outgoing-department option')).toHaveLength(1);
        expect(wrapper.get('#outgoing-department').attributes('readonly')).toBeUndefined();
        expect(wrapper.get('#outgoing-type option[value="9"]').text()).toBe('Letter');
        await wrapper.get('#outgoing-type').setValue(9);
        expect(wrapper.get('#outgoing-category').element.value).toBe('Correspondence');
        expect(wrapper.get('#outgoing-category').attributes('readonly')).toBeDefined();
        await wrapper.get('#outgoing-signatory_name').setValue('Example signatory');
        await wrapper.get('#outgoing-subject').setValue('Draft subject');
        await wrapper.get('#outgoing-document_date').setValue('2026-10-26');
        await wrapper.get('#outgoing-recipient-0-organization').setValue('First recipient');
        await button(wrapper, 'Add primary recipient').trigger('click');
        await wrapper.get('#outgoing-recipient-1-organization').setValue('Second recipient');
        await button(wrapper, 'Add copy recipient').trigger('click');
        await wrapper.get('#outgoing-recipient-2-organization').setValue('Copy recipient');
        await wrapper.get('#outgoing-draft-form').trigger('submit'); await flushPromises();
        const request = fetch.mock.calls.find(([, options]) => options.method === 'POST')[1];
        expect(request.headers['X-CSRF-TOKEN']).toBe('sample-csrf');
        const body = JSON.parse(request.body);
        expect(body).toMatchObject({ company_id: 4, site_id: 7, department_id: 12, type_id: 9 });
        expect(body.recipients.map(item => item.kind)).toEqual(['primary', 'primary', 'copy']);
        expect(body).not.toHaveProperty('internal_number'); expect(body).not.toHaveProperty('official_number');
        expect(wrapper.find('#outgoing-draft-form').exists()).toBe(false);
        expect(wrapper.text()).toContain('Second recipient');
    });
    it('edits and removes draft recipients without resubmitting immutable classification', async () => {
        const fetch = server(); const wrapper = await render(); await show(wrapper);
        await button(wrapper, 'Edit draft').trigger('click');
        expect(wrapper.find('#outgoing-classification').exists()).toBe(false);
        await wrapper.get('#outgoing-recipient-0-organization').setValue('Changed recipient');
        await wrapper.findAll('button').filter(item => item.text() === 'Remove recipient')[1].trigger('click');
        await wrapper.get('#outgoing-draft-form').trigger('submit'); await flushPromises();
        const body = JSON.parse(fetch.mock.calls.find(([, options]) => options.method === 'PATCH')[1].body);
        expect(body.recipients).toHaveLength(1); expect(body.recipients[0].organization).toBe('Changed recipient');
        expect(body).not.toHaveProperty('classification');
        expect(body.recipients[0]).not.toHaveProperty('id'); expect(body.recipients[0]).not.toHaveProperty('delivery');
    });
    it('confirms formal registration before sending and displays the two distinct numbers read-only', async () => {
        const fetch = server(); const wrapper = await render(); await show(wrapper);
        expect(wrapper.find('script').exists()).toBe(false);
        expect(wrapper.find('table').exists()).toBe(false);
        await button(wrapper, 'Register and assign numbers').trigger('click'); await flushPromises();
        expect(fetch.mock.calls.some(([url]) => url.includes('/register?'))).toBe(false);
        await button(wrapper.get('dialog'), 'Confirm').trigger('click'); await flushPromises();
        expect(wrapper.text()).toContain('TEST/OUT/2026/000001'); expect(wrapper.text()).toContain('TEST/OFFICIAL/2026/000001');
        expect(wrapper.text()).toContain('Registration numbers, issuance, ownership, document type and classification remain protected');
        expect(wrapper.findAll('button').some(item => item.text() === 'Edit draft')).toBe(false);
    });
    it('opts into XPPL official numbering while retaining the separate internal number', async () => {
        const fetch = server({ xppl: true }); const wrapper = await render(); await show(wrapper);
        const label = wrapper.findAll('label').find(item => item.text().trim() === en.numberingSelection);
        const select = wrapper.get(`#${label.attributes('for')}`);
        expect(select.element.value).toBe('');
        await select.setValue(4);
        await button(wrapper, 'Register and assign numbers').trigger('click'); await flushPromises();
        await button(wrapper.get('dialog'), 'Confirm').trigger('click'); await flushPromises();
        const [, options] = fetch.mock.calls.find(([url]) => url.includes('/register?'));
        expect(JSON.parse(options.body)).toMatchObject({ namespace_id: 4 });
        expect(wrapper.text()).toContain('XPPL-ENG-LTR-26-0001');
        expect(wrapper.text()).toContain('TEST/OUT/2026/000001');
    });
    it('shows nested recipient validation and preserves the draft editor', async () => {
        server({ mutationError: { status: 422, body: { errors: { 'recipients.0.organization': ['outgoingValidation'] } } } });
        const wrapper = await render(); await button(wrapper, 'Create outgoing draft').trigger('click');
        await wrapper.get('#outgoing-draft-form').trigger('submit'); await flushPromises();
        expect(wrapper.get('#outgoing-recipient-0-organization').attributes('aria-invalid')).toBe('true');
        expect(wrapper.text()).toContain('Check this field and try again.');
    });
    it.each([403, 404])('clears protected metadata and recipients when access is refused with %s', async status => {
        server({ detailError: { status, body: {} } }); const wrapper = await render(); await show(wrapper);
        expect(wrapper.text()).not.toContain(draft.subject); expect(wrapper.text()).not.toContain('recipient@example.test');
    });
    it('aborts a mutation on scope change and ignores its late recipient details', async () => {
        const fetch = server(); const wrapper = await render(); await button(wrapper, 'Create outgoing draft').trigger('click');
        let finish;
        fetch.mockImplementationOnce(() => new Promise(resolve => { finish = () => resolve(response({ data: { ...draft, subject: 'Old scope secret' } })); }));
        await wrapper.get('#outgoing-draft-form').trigger('submit');
        const signal = fetch.mock.calls.at(-1)[1].signal;
        await wrapper.setProps({ scope: { ...scope, key: '8:site', site_id: 8 } }); await flushPromises();
        expect(signal.aborted).toBe(true); finish(); await flushPromises();
        expect(wrapper.text()).not.toContain('Old scope secret');
    });
    it('renders Lao labels and escapes recipient details while readers see no mutation controls', async () => {
        for (const key of Object.keys(en).filter(key => key.startsWith('outgoing'))) { expect(lo[key], key).toBeTruthy(); expect(lo[key]).not.toBe(en[key]); }
        server(); const wrapper = await render({ ...scope, permissions: [] }); await show(wrapper);
        expect(wrapper.findAll('button').some(item => ['Create outgoing draft', 'Edit draft', 'Register and assign numbers'].includes(item.text()))).toBe(false);
        await wrapper.get('select[aria-label="Language"]').setValue('lo');
        expect(wrapper.text()).toContain('ທະບຽນເອກະສານຂາອອກ'); expect(wrapper.text()).toContain('ຜູ້ຮັບຫຼັກ');
        expect(wrapper.text()).toContain(lo.outgoingIssuingDepartment); expect(wrapper.text()).toContain(lo.documentCategory);
        expect(wrapper.find('script').exists()).toBe(false);
        expect(wrapper.find('table').exists()).toBe(false);
    });
    it('opens the outgoing register through an authorized sending context', async () => {
        server(); const wrapper = mount(OutgoingPage, { props: { endpoints: { endpoint: '/outgoing', contexts: '/outgoing/contexts' }, csrfToken: 'sample-csrf' }, global: { plugins: [createLocalization()] } });
        await flushPromises(); expect(wrapper.find('#outgoing-context').exists()).toBe(false);
        expect(wrapper.text()).toContain(draft.subject);
    });
    it('selects only authorized issuing departments inside a fresh form and clears stale fields on change', async () => {
        const administration = { ...scope, key: '7:13', department_id: 13, department: { id: 13, name_en: 'Administration', name_lo: 'ບໍລິຫານ' } };
        const siteWide = { ...scope, key: '7:site', department_id: null, department: null };
        localStorage.setItem('dcms.workspace.standalone', JSON.stringify({ site: '4:7', unit: scope.key }));
        const fetch = server({ contexts: [scope, administration, siteWide] });
        const wrapper = mount(OutgoingPage, { props: { endpoints: { endpoint: '/outgoing', contexts: '/outgoing/contexts' }, csrfToken: 'sample-csrf' }, global: { plugins: [createLocalization()] } });
        await flushPromises();

        expect(wrapper.get('label[for="outgoing-context"]').text()).toBe(en.outgoingIssuingDepartment);
        expect(wrapper.findAll('#outgoing-context option').map(option => option.text())).toEqual(['Engineering', 'Administration', en.siteWideDepartment]);
        await button(wrapper, en.outgoingCreate).trigger('click');
        expect(wrapper.find('#outgoing-context').exists()).toBe(false);
        expect(wrapper.findAll('#outgoing-department option').map(option => option.text())).toEqual(['Engineering', 'Administration', en.siteWideDepartment]);
        await wrapper.get('#outgoing-subject').setValue('Safe entered value');
        await wrapper.get('#outgoing-type').setValue(9);
        const formElement = wrapper.get('#outgoing-draft-form').element;
        let finishOldLookup;
        fetch.mockImplementationOnce((url, options) => new Promise(resolve => {
            finishOldLookup = () => resolve(response({ data: [{ ...type, id: 99 }], people: [] }));
        }));
        await wrapper.get('#outgoing-department').setValue('7:13'); await flushPromises();

        expect(wrapper.get('#outgoing-department').element.value).toBe('7:13');
        expect(wrapper.get('#outgoing-subject').element.value).toBe('Safe entered value');
        expect(wrapper.get('#outgoing-draft-form').element).toBe(formElement);
        expect(wrapper.get('#outgoing-type').element.value).toBe('');
        expect(wrapper.get('#outgoing-draft-form > p[role="status"]').text()).toBe(en.documentDepartmentLoading);
        expect(wrapper.get('#outgoing-draft-form button[type="submit"]').element.disabled).toBe(true);
        await wrapper.get('#outgoing-draft-form').trigger('submit');
        expect(fetch.mock.calls.some(([, options]) => options.method === 'POST')).toBe(false);
        expect(fetch.mock.calls.some(([url]) => url.includes('/outgoing/lookups?') && url.includes('department_id=13'))).toBe(true);

        await wrapper.get('#outgoing-department').setValue('7:site'); await flushPromises();
        finishOldLookup(); await flushPromises();
        expect(wrapper.find('#outgoing-type option[value="99"]').exists()).toBe(false);
        expect(wrapper.get('#outgoing-draft-form').element).toBe(formElement);
        expect(wrapper.get('#outgoing-draft-form button[type="submit"]').element.disabled).toBe(false);
        fetch.mockImplementationOnce(async () => response({}, 500));
        await wrapper.get('#outgoing-department').setValue('7:13'); await flushPromises();
        expect(wrapper.get('#outgoing-draft-form').element).toBe(formElement);
        expect(wrapper.get('[role="alert"]').text()).toContain(en.rbacRequestFailed);
        expect(wrapper.get('#outgoing-draft-form button[type="submit"]').element.disabled).toBe(true);
        await wrapper.get('#outgoing-draft-form').trigger('submit');
        expect(fetch.mock.calls.some(([, options]) => options.method === 'POST')).toBe(false);
        expect(wrapper.get('#outgoing-department').element.value).toBe('7:13');
        expect(fetch.mock.calls.some(([url]) => {
            const requestUrl = new URL(url, 'http://localhost');
            return requestUrl.pathname === '/outgoing/lookups' && requestUrl.searchParams.get('site_id') === '7' && !requestUrl.searchParams.has('department_id');
        })).toBe(true);
    });
    it('shows request failure separately from a genuine empty register', async () => {
        const fetch = server();
        fetch.mockImplementationOnce(async () => response({ message: 'Server error' }, 500));
        const wrapper = await render();
        expect(wrapper.text()).toContain(en.rbacRequestFailed);
        expect(wrapper.text()).not.toContain(en.uiEmpty);
        expect(wrapper.findAll('tbody tr')).toHaveLength(1);
    });
});
