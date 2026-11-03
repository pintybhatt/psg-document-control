import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import IncomingCorrespondence from '../../resources/js/components/IncomingCorrespondence.vue';
import IncomingPage from '../../resources/js/pages/IncomingPage.vue';
import LanguageSwitcher from '../../resources/js/components/LanguageSwitcher.vue';
import { createLocalization } from '../../resources/js/localization/index.js';
import en from '../../resources/js/localization/en.js';
import lo from '../../resources/js/localization/lo.js';

const scope = { key: '7:12', company_id: 4, site_id: 7, department_id: 12, department: { id: 12, name_en: 'Administration', name_lo: 'ບໍລິຫານ' }, permissions: ['incoming.drafts.manage', 'incoming.register', 'numbering.issue'] };
const type = { id: 9, name_en: 'Letter', name_lo: 'ຈົດໝາຍ', category: { id: 8, name_en: 'Correspondence', name_lo: 'ເອກະສານ' } };
const draft = { id: 1, type_id: 9, type, category: type.category, subject: '<script>test</script>', sender_organization: 'Example sender', sender_contact: 'Example contact', external_reference: 'EXT/22', correspondence_date: '2026-10-25', received_date: '2026-10-26', priority: 'normal', classification: 'normal', status: 'draft', internal_number: null };
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
function server({ mutationError = null, detailError = null, contexts = [scope] } = {}) {
    let record = structuredClone(draft);
    const fetch = vi.fn(async (url, options = {}) => {
        const path = new URL(url, 'http://localhost').pathname;
        const method = options.method ?? 'GET';
        if (path.endsWith('/contexts')) { return response({ data: contexts.map(item => ({ ...item, company: { name_en: 'Example company' }, site: { name_en: 'Example site' } })) }); }
        if (path.endsWith('/lookups')) { return response({ data: [type] }); }
        if (method === 'GET' && path === '/incoming') { return response({ data: [record], meta: { total: 1 } }); }
        if (method === 'GET') { return detailError ? response(detailError.body, detailError.status) : response({ data: record, audits: [{ id: 1, action: 'created', created_at: '2026-10-26', actor_id: 5 }] }); }
        if (mutationError) { return response(mutationError.body, mutationError.status); }
        const body = JSON.parse(options.body);
        if (path.endsWith('/register')) { record = { ...record, status: 'registered', internal_number: 'TEST/IN/2026/000001', registered_by: 5, registered_at: '2026-10-26T00:00:00Z' }; }
        else { record = { ...record, ...body }; }
        return response({ data: record }, method === 'POST' && path === '/incoming' ? 201 : 200);
    });
    vi.stubGlobal('fetch', fetch);
    return fetch;
}
async function render(currentScope = scope) {
    const wrapper = mount({
        components: { IncomingCorrespondence, LanguageSwitcher }, props: { scope: Object },
        template: '<div id="dcms-app"><LanguageSwitcher /><IncomingCorrespondence :scope="scope" endpoint="/incoming" csrf-token="sample-csrf" /></div>',
    }, { props: { scope: structuredClone(currentScope) }, attachTo: document.body, global: { plugins: [createLocalization()] } });
    await flushPromises(); return wrapper;
}
function button(wrapper, text) { const found = wrapper.findAll('button').find(item => item.text() === text); expect(found, text).toBeDefined(); return found; }
async function openDetail(wrapper) { await button(wrapper, 'Details').trigger('click'); await flushPromises(); }

describe('incoming correspondence', () => {
    it('filters the register and supplies native report downloads with the selected scope and filters', async () => {
        const fetch = server(); const wrapper = await render();
        await wrapper.get('#incoming-filter-search').setValue('Example');
        await wrapper.get('#incoming-filter-status').setValue('in_progress');
        await wrapper.get('#incoming-filter-channel').setValue('email');
        await button(wrapper, 'Apply filters').trigger('submit'); await flushPromises();
        expect(fetch.mock.calls.at(-1)[0]).toContain('filter_status=in_progress');
        expect(fetch.mock.calls.at(-1)[0]).toContain('filter_channel=email');
        expect(wrapper.get('a[href*="/export"]').attributes('href')).toContain('search=Example');
        expect(wrapper.get('a[href*="/export"]').attributes('href')).toContain('format=xlsx');
        expect(button(wrapper, 'Preview PDF')).toBeDefined();
        expect(wrapper.find('a[target="_blank"]').exists()).toBe(false);
    });
    it('loads exact scope, renders escaped metadata and opens authorized detail', async () => {
        const fetch = server(); const wrapper = await render();
        expect(fetch.mock.calls[0][0]).toContain('company_id=4&site_id=7');
        expect(wrapper.text()).toContain(draft.subject); expect(wrapper.find('script').exists()).toBe(false);
        await openDetail(wrapper);
        expect(wrapper.text()).toContain('EXT/22'); expect(wrapper.text()).toContain('Draft created');
        expect(wrapper.text()).toContain('Document typeLetter');
        expect(wrapper.text()).toContain('Document categoryCorrespondence');
    });
    it('creates a draft with CSRF and without a generated number then shows the saved detail', async () => {
        const fetch = server(); const wrapper = await render();
        await button(wrapper, 'Create incoming draft').trigger('click');
        expect(wrapper.get('#incoming-department').element.value).toBe('Administration');
        expect(wrapper.get('#incoming-department').attributes('readonly')).toBeDefined();
        expect(wrapper.get('#incoming-type option[value="9"]').text()).toBe('Letter');
        await wrapper.get('#incoming-type').setValue('9');
        expect(wrapper.get('#incoming-category').element.value).toBe('Correspondence');
        expect(wrapper.get('#incoming-category').attributes('readonly')).toBeDefined();
        await wrapper.get('#incoming-channel').setValue('email');
        await wrapper.get('#incoming-sender_organization').setValue('Example sender');
        await wrapper.get('#incoming-subject').setValue('New subject');
        await wrapper.get('#incoming-correspondence_date').setValue('2026-10-25');
        await wrapper.get('#incoming-received_date').setValue('2026-10-26');
        await wrapper.get('#incoming-draft-form').trigger('submit'); await flushPromises();
        const call = fetch.mock.calls.find(([, options]) => options.method === 'POST');
        expect(call[1].headers['X-CSRF-TOKEN']).toBe('sample-csrf');
        expect(JSON.parse(call[1].body)).toMatchObject({ subject: 'New subject', classification: 'normal', site_id: 7, department_id: 12 });
        expect(JSON.parse(call[1].body)).not.toHaveProperty('internal_number');
        expect(wrapper.find('#incoming-draft-form').exists()).toBe(false); expect(wrapper.text()).toContain('Draft saved.');
    });
    it('edits a draft without changing its security classification', async () => {
        const fetch = server(); const wrapper = await render(); await openDetail(wrapper);
        await button(wrapper, 'Edit draft').trigger('click');
        expect(wrapper.find('#incoming-classification').exists()).toBe(false);
        await wrapper.get('#incoming-subject').setValue('Revised'); await wrapper.get('#incoming-draft-form').trigger('submit'); await flushPromises();
        const call = fetch.mock.calls.find(([, options]) => options.method === 'PATCH');
        expect(JSON.parse(call[1].body).subject).toBe('Revised'); expect(JSON.parse(call[1].body)).not.toHaveProperty('classification');
    });
    it('confirms registration and displays the issued number with read-only history', async () => {
        const fetch = server(); const wrapper = await render(); await openDetail(wrapper);
        await button(wrapper, 'Register formally').trigger('click'); await flushPromises();
        expect(fetch.mock.calls.some(([url]) => url.includes('/register?'))).toBe(false);
        await button(wrapper.get('dialog'), 'Confirm').trigger('click'); await flushPromises();
        expect(wrapper.text()).toContain('TEST/IN/2026/000001'); expect(wrapper.text()).toContain('Registration numbers, issuance, ownership, document type and classification remain protected');
        expect(wrapper.findAll('button').some(item => item.text() === 'Edit draft')).toBe(false);
    });
    it('shows translated validation errors and retains the draft form', async () => {
        server({ mutationError: { status: 422, body: { errors: { subject: ['incomingValidation'] } } } });
        const wrapper = await render(); await button(wrapper, 'Create incoming draft').trigger('click');
        await wrapper.get('#incoming-draft-form').trigger('submit'); await flushPromises();
        expect(wrapper.find('#incoming-draft-form').exists()).toBe(true); expect(wrapper.get('#incoming-subject').attributes('aria-invalid')).toBe('true');
        expect(wrapper.text()).toContain('Check this field and try again.');
    });
    it.each([403, 404])('clears protected records after detail access fails with %s', async status => {
        server({ detailError: { status, body: {} } }); const wrapper = await render(); await openDetail(wrapper);
        expect(wrapper.text()).not.toContain(draft.subject); expect(wrapper.text()).not.toContain('EXT/22');
    });
    it('hides mutations for readers', async () => {
        server(); const wrapper = await render({ ...scope, permissions: [] }); await openDetail(wrapper);
        for (const text of ['Create incoming draft', 'Edit draft', 'Register formally']) { expect(wrapper.findAll('button').some(item => item.text() === text)).toBe(false); }
    });
    it('translates all incoming labels and switches list and detail to Lao', async () => {
        for (const key of Object.keys(en).filter(key => key.startsWith('incoming'))) { expect(lo[key], key).toBeTruthy(); expect(lo[key]).not.toBe(en[key]); }
        server(); const wrapper = await render(); await openDetail(wrapper);
        await wrapper.get('select[aria-label="Language"]').setValue('lo');
        expect(wrapper.text()).toContain('ທະບຽນເອກະສານຂາເຂົ້າ'); expect(wrapper.text()).toContain('ຈົດໝາຍ');
        expect(wrapper.text()).toContain(lo.incomingReceivingDepartment); expect(wrapper.text()).toContain(lo.documentCategory);
        expect(wrapper.text()).not.toContain('incomingStatus-');
        expect(wrapper.find('table').exists()).toBe(false);
    });
    it('aborts old scope reads and ignores their late response', async () => {
        const fetch = server(); let resolveOld;
        fetch.mockImplementationOnce((url, options) => new Promise(resolve => { resolveOld = () => resolve(response({ data: [draft], meta: { total: 1 } })); }));
        const wrapper = await render(); const oldSignal = fetch.mock.calls[0][1].signal;
        await wrapper.setProps({ scope: { ...scope, key: '8:site', site_id: 8 } }); await flushPromises();
        expect(oldSignal.aborted).toBe(true);
        const before = fetch.mock.calls.length; resolveOld(); await flushPromises();
        expect(fetch.mock.calls.length).toBe(before);
    });
    it('clears an in-flight mutation result when receiving context changes', async () => {
        const fetch = server(); const wrapper = await render(); await button(wrapper, 'Create incoming draft').trigger('click');
        let finish;
        fetch.mockImplementationOnce(() => new Promise(resolve => { finish = () => resolve(response({ data: { ...draft, subject: 'Old scope secret' } })); }));
        await wrapper.get('#incoming-draft-form').trigger('submit');
        await wrapper.setProps({ scope: { ...scope, key: '8:site', site_id: 8 } }); await flushPromises();
        finish(); await flushPromises(); expect(wrapper.text()).not.toContain('Old scope secret');
    });
    it('integrates authorized receiving contexts into the incoming page', async () => {
        server(); const wrapper = mount(IncomingPage, { props: { endpoints: { endpoint: '/incoming', contexts: '/incoming/contexts' }, csrfToken: 'sample-csrf' }, global: { plugins: [createLocalization()] } });
        await flushPromises(); expect(wrapper.find('#incoming-context').exists()).toBe(false);
        expect(wrapper.text()).toContain('Example sender');
    });
    it('labels and changes only between authorized receiving departments', async () => {
        const finance = { ...scope, key: '7:13', department_id: 13, department: { id: 13, name_en: 'Finance', name_lo: 'ການເງິນ' } };
        const fetch = server({ contexts: [scope, finance] });
        const wrapper = mount(IncomingPage, { props: { endpoints: { endpoint: '/incoming', contexts: '/incoming/contexts' }, csrfToken: 'sample-csrf' }, global: { plugins: [createLocalization()] } });
        await flushPromises();

        expect(wrapper.get('label[for="incoming-context"]').text()).toBe(en.incomingReceivingDepartment);
        expect(wrapper.findAll('#incoming-context option').map(option => option.text())).toEqual(['Administration', 'Finance']);
        await wrapper.get('#incoming-context').setValue('7:13'); await flushPromises();
        expect(fetch.mock.calls.some(([url]) => url.includes('/incoming?') && url.includes('department_id=13'))).toBe(true);
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
