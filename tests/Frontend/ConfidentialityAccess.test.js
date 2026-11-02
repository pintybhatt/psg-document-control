import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import ConfidentialityAccess from '../../resources/js/components/ConfidentialityAccess.vue';
import AdministrationPage from '../../resources/js/pages/AdministrationPage.vue';
import LanguageSwitcher from '../../resources/js/components/LanguageSwitcher.vue';
import { createLocalization } from '../../resources/js/localization/index.js';

const scope = { scope_key: 'site:7', scope_type: 'site', company_id: 4, site_id: 7, company: { name_en: 'Example Company' }, site: { name_en: 'Example Site' }, permissions: [], can_manage_document_security: true };
const permissions = ['clearances.confidential.manage', 'clearances.highly-confidential.manage', 'document-access.manage'];
const siteContext = { department_id: null, department: null, permissions, can_manage_documents: true };
const departmentContext = { ...siteContext, department_id: 8, department: { name_en: 'Example Department', name_lo: 'ພະແນກຕົວຢ່າງ' } };
const user = { id: 9, name: 'Example User', email: 'example@example.test' };
const clearance = { id: 11, user, classification: 'confidential', operation: null, status: 'active', expires_at: '2027-01-01T00:00:00Z', revoked_at: null, revocable: true };
const sampleDocument = { id: 20, register: 'incoming', number: 'EXAMPLE/001', title_en: 'Example letter', title_lo: 'ຈົດໝາຍຕົວຢ່າງ' };
const descriptors = Object.getOwnPropertyDescriptors(HTMLDialogElement.prototype);
enableAutoUnmount(afterEach);
beforeAll(() => Object.defineProperties(HTMLDialogElement.prototype, {
    showModal: { configurable: true, value() { this.setAttribute('open', ''); } },
    close: { configurable: true, value() { if (this.open) { this.removeAttribute('open'); this.dispatchEvent(new Event('close')); } } },
}));
afterAll(() => {
    for (const key of ['showModal', 'close']) {
        if (descriptors[key]) { Object.defineProperty(HTMLDialogElement.prototype, key, descriptors[key]); } else { delete HTMLDialogElement.prototype[key]; }
    }
});
beforeEach(() => localStorage.clear());
afterEach(() => { document.body.replaceChildren(); vi.unstubAllGlobals(); });
const response = (data, status = 200) => ({ ok: status < 400, status, json: async () => structuredClone(data) });
function server({ contexts = [siteContext], clearanceRows = [clearance], grantRows = [], mutationError = null, adminScope = scope } = {}) {
    let clearances = structuredClone(clearanceRows);
    let grants = structuredClone(grantRows);
    const fetch = vi.fn(async (url, options = {}) => {
        const path = new URL(url, 'http://localhost').pathname;
        const method = options.method ?? 'GET';
        if (path === '/administration-contexts') { return response({ data: [adminScope] }); }
        if (method !== 'GET' && mutationError) { return response(mutationError.data, mutationError.status); }
        if (path.endsWith('/contexts')) { return response({ data: contexts }); }
        if (path.endsWith('/users')) { return response({ data: [user], meta: { total: 1 } }); }
        if (path.endsWith('/clearances') && method === 'GET') { return response({ data: clearances, meta: { total: clearances.length } }); }
        if (path.endsWith('/documents')) { return response({ data: [sampleDocument], meta: { total: 1 } }); }
        if (path.endsWith('/grants') && method === 'GET') { return response({ data: grants, meta: { total: grants.length } }); }
        if (path.endsWith('/clearances') && method === 'POST') { clearances.push({ ...clearance, id: 12 }); return response({ message: 'confidentialitySaved' }, 201); }
        if (path.endsWith('/grants') && method === 'POST') {
            grants.push({ ...clearance, classification: null, operation: 'metadata' }); return response({ message: 'confidentialitySaved' }, 201);
        }
        if (method === 'DELETE') {
            const records = path.includes('/clearances/') ? clearances : grants;
            const row = records.find(row => row.id === Number(path.split('/').at(-1)));
            Object.assign(row, { status: 'revoked', revoked_at: '2026-11-02T12:00:00Z', revocable: false });
            return response({ message: 'confidentialityRevoked' });
        }
        throw new Error(`Unexpected request ${method} ${path}`);
    });
    vi.stubGlobal('fetch', fetch);
    return fetch;
}
async function render() {
    const wrapper = mount({ components: { ConfidentialityAccess, LanguageSwitcher }, setup: () => ({ scope }), template: '<div id="dcms-app"><LanguageSwitcher /><ConfidentialityAccess :scope="scope" endpoint="/confidentiality" csrf-token="sample-csrf" /></div>' }, { attachTo: document.body, global: { plugins: [createLocalization()] } });
    await flushPromises();
    return wrapper;
}
function button(wrapper, text) { const result = wrapper.findAll('button').find(item => item.text() === text); expect(result, text).toBeDefined(); return result; }
function control(wrapper, text) { const label = wrapper.findAll('label').find(item => item.text().trim() === text); expect(label, text).toBeDefined(); return wrapper.get(`#${label.attributes('for')}`); }
async function chooseUser(wrapper) {
    await button(wrapper, 'Find eligible users').trigger('click'); await flushPromises();
    await control(wrapper, 'Account').setValue(user.id);
}
const mutations = fetch => fetch.mock.calls.filter(([, options]) => ['POST', 'DELETE'].includes(options.method));

describe('confidentiality administration', () => {
    it('appears in Administration for security-only scope without loading role APIs', async () => {
        const fetch = server();
        const wrapper = mount(AdministrationPage, { props: { endpoints: { contexts: '/administration-contexts', confidentiality: '/confidentiality' }, csrfToken: 'sample-csrf' }, global: { plugins: [createLocalization()] } });
        await flushPromises();
        expect(wrapper.text()).toContain('Confidentiality & Access');
        expect(wrapper.text()).toContain('User Clearances');
        expect(wrapper.text()).not.toContain('Create role');
        expect(fetch.mock.calls.every(([url]) => /administration-contexts|confidentiality/.test(url))).toBe(true);
        expect(mutations(fetch)).toHaveLength(0);
    });

    it('does not show the section when the backend has not authorized security management', async () => {
        const fetch = server({ adminScope: { ...scope, can_manage_document_security: false } });
        const wrapper = mount(AdministrationPage, { props: { endpoints: { contexts: '/administration-contexts', confidentiality: '/confidentiality' }, csrfToken: 'sample-csrf' }, global: { plugins: [createLocalization()] } });
        await flushPromises();
        expect(wrapper.text()).not.toContain('Confidentiality & Access');
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('grants only the selected classification with scope, expiry and CSRF protection', async () => {
        const fetch = server({ clearanceRows: [] });
        const wrapper = await render();
        await chooseUser(wrapper);
        await control(wrapper, 'Confidentiality classification').setValue('highly_confidential');
        await control(wrapper, 'Expiry (optional)').setValue('2027-01-02T10:30');
        await button(wrapper, 'Grant clearance').trigger('click'); await flushPromises();
        const [url, options] = mutations(fetch)[0];
        expect(url).toContain('/clearances?');
        expect(JSON.parse(options.body)).toEqual({ company_id: 4, site_id: 7, department_id: null, user_id: 9, expires_at: '2027-01-02T03:30:00.000Z', classification: 'highly_confidential' });
        expect(options.headers['X-CSRF-TOKEN']).toBe('sample-csrf');
        expect(wrapper.text()).toContain('Access granted and recorded');
    });

    it('requires confirmation to revoke and preserves historical statuses', async () => {
        const fetch = server({ clearanceRows: [clearance, { ...clearance, id: 12, status: 'expired', revocable: false }, { ...clearance, id: 13, status: 'revoked', revocable: false, revoked_at: '2026-11-02T12:00:00Z' }] });
        const wrapper = await render();
        expect(wrapper.text()).toContain('Expired');
        expect(wrapper.text()).toContain('Revoked');
        expect(wrapper.text()).toContain('01/01/2027, 07:00');
        await button(wrapper, 'Revoke access').trigger('click'); await flushPromises();
        expect(wrapper.get('dialog').attributes('open')).toBeDefined();
        expect(wrapper.get('dialog').text()).toContain('Example User');
        expect(mutations(fetch)).toHaveLength(0);
        await button(wrapper.get('dialog'), 'Confirm').trigger('click'); await flushPromises();
        expect(mutations(fetch)).toHaveLength(1);
        expect(mutations(fetch)[0][1].method).toBe('DELETE');
        expect(wrapper.text()).toContain('Access revoked. History is preserved.');
    });

    it('hides unauthorized functions and only offers permitted clearance classifications', async () => {
        const fetch = server({ contexts: [{ ...siteContext, permissions: ['clearances.confidential.manage'], can_manage_documents: false }] });
        const wrapper = await render();
        expect(wrapper.findAll('h4').map(item => item.text())).toEqual(['User Clearances']);
        expect(control(wrapper, 'Confidentiality classification').findAll('option').map(item => item.element.value)).toEqual(['confidential']);
        expect(fetch.mock.calls.some(([url]) => url.includes('/documents'))).toBe(false);
        expect(mutations(fetch)).toHaveLength(0);
    });

    it('clears selected users and documents when changing to exact department scope', async () => {
        const fetch = server({ contexts: [siteContext, departmentContext] });
        const wrapper = await render();
        await chooseUser(wrapper);
        await control(wrapper, 'Security scope').setValue('8'); await flushPromises();
        expect(control(wrapper, 'Account').element.value).toBe('');
        expect(fetch.mock.calls.at(-1)[0]).toContain('department_id=8');
        await chooseUser(wrapper);
        await button(wrapper, 'Grant clearance').trigger('click'); await flushPromises();
        expect(JSON.parse(mutations(fetch)[0][1].body).department_id).toBe(8);
    });

    it('finds a visible document and grants only explicitly selected operations', async () => {
        const fetch = server();
        const wrapper = await render();
        await chooseUser(wrapper);
        await control(wrapper, 'Document number or title').setValue('EXAMPLE');
        await button(wrapper, 'Find documents').trigger('click'); await flushPromises();
        expect(wrapper.text()).toContain('Example letter');
        await button(wrapper, 'Manage access').trigger('click'); await flushPromises();
        expect(button(wrapper, 'Grant document access').attributes('disabled')).toBeDefined();
        await wrapper.get('input[type="checkbox"][value="preview"]').setValue(true);
        await wrapper.get('input[type="checkbox"][value="download"]').setValue(true);
        await button(wrapper, 'Grant document access').trigger('click'); await flushPromises();
        const [url, options] = mutations(fetch)[0];
        expect(url).toContain('/documents/incoming/20/grants?');
        expect(JSON.parse(options.body)).toEqual({ company_id: 4, site_id: 7, department_id: null, user_id: 9, expires_at: null, operations: ['preview', 'download'] });
        expect(wrapper.text()).toContain('Existing document access grants');
    });

    it('shows validation errors without discarding the form or sending a second mutation', async () => {
        const fetch = server({ mutationError: { status: 422, data: { errors: { security: ['documentSecurityInvalid'] } } } });
        const wrapper = await render(); await chooseUser(wrapper);
        await button(wrapper, 'Grant clearance').trigger('click'); await flushPromises();
        expect(wrapper.find('[role="alert"]').exists()).toBe(true);
        expect(control(wrapper, 'Account').element.value).toBe('9');
        expect(mutations(fetch)).toHaveLength(1);
    });

    it('removes sensitive rows and reports revoked authority on a forbidden mutation', async () => {
        server({ mutationError: { status: 403, data: { message: 'documentSecurityForbidden' } } });
        const wrapper = await render(); await chooseUser(wrapper);
        await button(wrapper, 'Grant clearance').trigger('click'); await flushPromises();
        expect(wrapper.text()).not.toContain('Example User');
        expect(wrapper.findComponent(ConfidentialityAccess).emitted('unauthorized')).toHaveLength(1);
        expect(wrapper.get('[role="alert"]').text()).not.toContain('documentSecurityForbidden');
    });

    it('translates controls, scope and statuses into Lao', async () => {
        server({ contexts: [departmentContext] });
        const wrapper = await render();
        await wrapper.get('select[aria-label="Language"]').setValue('lo');
        expect(wrapper.text()).toContain('ຄວາມລັບ ແລະ ການເຂົ້າເຖິງ');
        expect(wrapper.text()).toContain('ພະແນກຕົວຢ່າງ');
        expect(wrapper.text()).toContain('ໃຫ້ສິດຂໍ້ມູນລັບ');
        expect(wrapper.text()).not.toMatch(/confidentiality[A-Z-]|documentOperation-/);
    });
});
