import { afterEach, expect, it, vi } from 'vitest';
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import { defineComponent } from 'vue';
import ReportsPage from '../../resources/js/pages/ReportsPage.vue';
import { createWorkspace, provideWorkspace } from '../../resources/js/utilities/workspace.js';
import { createLocalization } from '../../resources/js/localization/index.js';
import en from '../../resources/js/localization/en.js';
import lo from '../../resources/js/localization/lo.js';

enableAutoUnmount(afterEach);
afterEach(() => { vi.unstubAllGlobals(); localStorage.clear(); window.history.replaceState({}, '', '/'); });
const context = { key: '2:site', company_id: 1, site_id: 2, department_id: null, permissions: ['reports.view'], company: { name_en: 'Demo company', name_lo: 'ບໍລິສັດ' }, site: { name_en: 'Demo site', name_lo: 'ສະຖານທີ່' } };
const record = { key: 'incoming:1', id: 1, register: 'incoming', number: 'DEMO/IN/001', title_en: '<script>Synthetic</script>', title_lo: 'ເອກະສານທົດສອບ', status: 'received', document_date: '2026-10-26', href: '/incoming?unit=2:site&record=1' };
function response(body, status = 200) { return { ok: status < 400, status, json: async () => structuredClone(body) }; }
async function render(language = 'en', permissions = ['reports.view']) {
    const fetch = vi.fn(async url => {
        if (url.includes('/lookups')) { return response({ data: [{ id: 4, category_id: 3, name_en: 'Letter', name_lo: 'ຈົດໝາຍ', category: { id: 3, name_en: 'Correspondence', name_lo: 'ຈົດໝາຍ' }, registers: ['incoming'] }] }); }
        const query = new URL(url, 'http://localhost').searchParams;
        return response({ data: [record], meta: { total: 1 }, summary: { total: 1, open_incoming: 1, pending_response: 0, closed: 0 }, capabilities: { include_unattributed: true }, trends: ['audit', 'security'].includes(query.get('view')) ? null : { [query.get('trend_group') || 'category_en']: [] } });
    });
    vi.stubGlobal('fetch', fetch);
    const workspace = createWorkspace('report', null, 'token'); workspace.contexts.value = [{ ...context, permissions }]; workspace.siteKey.value = '1:2'; workspace.unitKey.value = '2:site';
    const wrapper = mount(defineComponent({ components: { ReportsPage }, setup() { provideWorkspace(workspace); }, template: '<ReportsPage endpoint="/reports" csrf-token="token" />' }), { global: { plugins: [createLocalization(language)], stubs: { PdfPreviewDialog: { props: ['url'], template: '<div data-preview :data-url="url" />' } } } });
    await flushPromises(); return { wrapper, fetch, workspace };
}
it.each(['en', 'lo'])('renders authorized bilingual report rows and date controls in %s', async language => {
    const { wrapper } = await render(language); const dictionary = language === 'lo' ? lo : en;
    expect(wrapper.text()).toContain(dictionary['reportView-documents']);
    expect(wrapper.text()).not.toMatch(/metric-|reportView-/);
    expect(wrapper.text()).toContain('26/10/2026'); expect(wrapper.find('script').exists()).toBe(false);
    expect(wrapper.get('#report-from').attributes('placeholder')).toBe('dd/mm/yyyy');
    expect(wrapper.findAll('a').some(a => a.attributes('href') === record.href)).toBe(true);
});
it('freezes applied filters for XLSX and PDF until the next report request', async () => {
    const { wrapper, fetch } = await render();
    await wrapper.get('#report-from').setValue('01/10/2026'); await wrapper.get('#report-search').setValue('safety');
    await wrapper.get('form').trigger('submit'); await flushPromises();
    await wrapper.get('#report-search').setValue('unapplied change');
    const link = wrapper.findAll('a').find(a => a.text() === en.reportDownloadXlsx).attributes('href');
    expect(link).toContain('search=safety'); expect(link).toContain('date_from=2026-10-01'); expect(link).not.toContain('unapplied');
    await wrapper.findAll('button').find(b => b.text() === en.reportPreviewPdf).trigger('click'); await flushPromises();
    expect(wrapper.get('[data-preview]').attributes('data-url')).toContain('format=pdf');
    expect(wrapper.get('[data-preview]').attributes('data-url')).toContain('search=safety');
    expect(fetch.mock.calls.at(-1)[0]).toContain('company_id=1');
});
it('requires explicit report permission and clears rows on context revocation', async () => {
    const denied = await render('en', []); expect(denied.fetch).not.toHaveBeenCalled(); expect(denied.wrapper.text()).toContain(en.reportNoPermission);
    const { wrapper, workspace } = await render(); workspace.revoke(); await flushPromises();
    expect(wrapper.text()).not.toContain('DEMO/IN/001'); expect(wrapper.find('table').exists()).toBe(false);
});
it('switches to controlled register for revision reports and preserves exact workspace', async () => {
    const { wrapper, fetch } = await render();
    await wrapper.get('#report-view').setValue('revisions'); await flushPromises();
    const params = new URL(fetch.mock.calls.filter(([url]) => !url.includes('/lookups')).at(-1)[0], 'http://localhost').searchParams;
    expect(params.get('register')).toBe('controlled'); expect(params.get('view')).toBe('revisions'); expect(params.get('site_id')).toBe('2');
});
it('shows validation failures without stale report totals or export links', async () => {
    const { wrapper, fetch } = await render(); fetch.mockImplementationOnce(async () => response({ message: 'Invalid', errors: { date_from: ['reportValidation'] } }, 422));
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(wrapper.text()).toContain(en.reportValidation); expect(wrapper.text()).not.toContain('DEMO/IN/001');
    expect(wrapper.findAll('a')).toHaveLength(0);
});

it('offers audit-only users their explicitly permitted view without management or security reports', async () => {
    const { wrapper, fetch } = await render('en', ['documents.audit.view']);
    expect(wrapper.get('#report-view').element.value).toBe('audit');
    const options = wrapper.get('#report-view').findAll('option').map(option => option.element.value);
    expect(options).toEqual(['audit']);
    expect(fetch.mock.calls.some(([url]) => url.includes('view=audit'))).toBe(true);
    expect(fetch.mock.calls.filter(([url]) => !url.includes('/lookups'))).toHaveLength(1);
    expect(fetch.mock.calls.filter(([url]) => url.includes('/lookups'))).toHaveLength(1);
    expect(wrapper.find('#report-outcome').exists()).toBe(false);
});

it('loads once when switching views also resets unattributed events', async () => {
    const { wrapper, fetch } = await render('en', ['reports.view', 'security.audit.view']);
    await wrapper.get('#report-view').setValue('security'); await flushPromises();
    await wrapper.get('#report-unattributed').setValue(true); await flushPromises();
    fetch.mockClear();

    await wrapper.get('#report-view').setValue('documents'); await flushPromises();

    expect(fetch.mock.calls.filter(([url]) => !url.includes('/lookups'))).toHaveLength(1);
    expect(fetch.mock.calls.filter(([url]) => url.includes('/lookups'))).toHaveLength(1);
    expect(new URL(fetch.mock.calls[0][0], 'http://localhost').searchParams.get('include_unattributed')).toBe('0');
});

it.each(['en', 'lo'])('requests only the selected breakdown and retains applied filters in %s', async language => {
    const { wrapper, fetch } = await render(language);
    expect(new URL(fetch.mock.calls[0][0], 'http://localhost').searchParams.get('trend_group')).toBe('category_en');
    await wrapper.get('#report-search').setValue('applied search');
    await wrapper.get('form').trigger('submit'); await flushPromises();
    await wrapper.get('#report-search').setValue('unapplied search');
    fetch.mockClear();

    await wrapper.get('#report-breakdown').setValue('status'); await flushPromises();

    expect(fetch).toHaveBeenCalledTimes(1);
    const query = new URL(fetch.mock.calls[0][0], 'http://localhost').searchParams;
    expect(query.get('trend_group')).toBe('status');
    expect(query.get('search')).toBe('applied search');
    expect(wrapper.get('#report-search').element.value).toBe('unapplied search');
});

it('does not reload lookups when only unattributed-event inclusion changes', async () => {
    const { wrapper, fetch } = await render('en', ['security.audit.view']);
    fetch.mockClear();

    await wrapper.get('#report-unattributed').setValue(true); await flushPromises();

    expect(fetch).toHaveBeenCalledTimes(1);
    expect(new URL(fetch.mock.calls[0][0], 'http://localhost').searchParams.get('include_unattributed')).toBe('1');
});

it('aborts the earlier request and ignores a late sensitive response after a view change', async () => {
    const { wrapper, fetch } = await render();
    let finish;
    fetch.mockImplementationOnce(() => new Promise(resolve => { finish = () => resolve(response({ data: [{ ...record, title_en: 'Stale sensitive result', number: 'STALE/SECRET' }], meta: { total: 99 }, summary: { total: 99 } })); }));
    await wrapper.get('form').trigger('submit');
    const signal = fetch.mock.calls.at(-1)[1].signal;

    await wrapper.get('#report-view').setValue('revisions'); await flushPromises();
    expect(signal.aborted).toBe(true);
    finish(); await flushPromises();

    expect(wrapper.text()).not.toContain('STALE/SECRET');
    expect(wrapper.text()).not.toContain('Stale sensitive result');
    expect(wrapper.get('#report-view').element.value).toBe('revisions');
    expect(new URL(fetch.mock.calls.filter(([url]) => !url.includes('/lookups')).at(-1)[0], 'http://localhost').searchParams.get('register')).toBe('controlled');
});

it('finishes current-workspace lookups when a breakdown change interrupts an earlier lookup', async () => {
    const { wrapper, fetch, workspace } = await render();
    const normalFetch = fetch.getMockImplementation();
    let finish, oldSignal;
    fetch.mockImplementation((url, options) => {
        if (url.includes('/lookups') && !finish) {
            oldSignal = options.signal;
            return new Promise(resolve => { finish = () => resolve(response({ data: [{ id: 99, name_en: 'Stale workspace type', category: { id: 99 }, registers: ['incoming'] }] })); });
        }
        return normalFetch(url, options);
    });
    workspace.contexts.value = [context, { ...context, key: '2:7', department_id: 7, department: { name_en: 'Department', name_lo: 'ພະແນກ' } }];
    workspace.unitKey.value = '2:7'; await flushPromises();

    await wrapper.get('#report-breakdown').setValue('status'); await flushPromises();
    expect(oldSignal.aborted).toBe(true);
    expect(wrapper.get('#report-type').text()).toContain('Letter');
    finish(); await flushPromises();

    expect(wrapper.get('#report-type').text()).not.toContain('Stale workspace type');
    const query = new URL(fetch.mock.calls.at(-1)[0], 'http://localhost').searchParams;
    expect(query.get('department_id')).toBe('7');
});
