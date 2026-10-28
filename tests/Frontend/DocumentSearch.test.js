import { afterEach, expect, it, vi } from 'vitest';
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import { defineComponent } from 'vue';
import DocumentSearchPage from '../../resources/js/pages/DocumentSearchPage.vue';
import { createWorkspace, provideWorkspace } from '../../resources/js/utilities/workspace.js';
import { createLocalization } from '../../resources/js/localization/index.js';
import en from '../../resources/js/localization/en.js';
import lo from '../../resources/js/localization/lo.js';

const scope = { key: '7:site', company_id: 4, site_id: 7, department_id: null, permissions: [], company: { name_en: 'Demo' }, site: { name_en: 'Demo site' } };
const record = { key: 'controlled:1', id: 1, register: 'controlled', number: 'DEMO/SOP/001', title_en: '<script>Safety</script>', title_lo: 'ຄວາມປອດໄພ', type: { name_en: 'Procedure', name_lo: 'ລະບຽບ' }, category: { name_en: 'Safety', name_lo: 'ຄວາມປອດໄພ' }, document_date: '2026-10-26', status: 'current', authoritative_revision: 0, draft_revision: 1, physical_copies: [{ identifier: 'COPY/001', kind: 'original', location: 'Room A → Cabinet B', status: 'checked_out', custodian: 'Demo Officer' }], detail_url: '/controlled-documents?unit=7:site&record=1' };
enableAutoUnmount(afterEach);
afterEach(() => { vi.unstubAllGlobals(); localStorage.clear(); window.history.replaceState({}, '', '/'); });
function response(body, status = 200) { return { ok: status < 400, status, json: async () => structuredClone(body) }; }
async function render(language = 'en', status = 200) {
    const fetch = vi.fn(async url => url.includes('/lookups') ? response({ data: [] }) : response({ data: [record], meta: { total: 21 } }, status));
    vi.stubGlobal('fetch', fetch);
    const workspace = createWorkspace('search', null, 'token'); workspace.contexts.value = [scope]; workspace.siteKey.value = '4:7'; workspace.unitKey.value = '7:site';
    const wrapper = mount(defineComponent({ components: { DocumentSearchPage }, setup() { provideWorkspace(workspace); }, template: '<DocumentSearchPage endpoint="/search" csrf-token="token" />' }), { global: { plugins: [createLocalization(language)] } });
    await flushPromises(); return { wrapper, fetch, workspace };
}
it.each(['en', 'lo'])('renders localized identities, released/draft revisions, locations and safe detail links in %s', async language => {
    const { wrapper } = await render(language);
    const text = language === 'en' ? en : lo;
    expect(wrapper.text()).toContain(text.controlledAuthoritative);
    expect(wrapper.text()).toContain(`${text.controlledAuthoritative}: R00`);
    expect(wrapper.text()).toContain(`${text['controlledStatus-draft']}: R01`);
    expect(wrapper.text()).toContain('Room A → Cabinet B');
    expect(wrapper.find('script').exists()).toBe(false);
    expect(wrapper.findAll('a').every(link => link.attributes('href') === record.detail_url)).toBe(true);
    expect(wrapper.text()).toContain('26/10/2026');
});
it('submits combined filters, scopes, padded revisions and server pagination', async () => {
    const { wrapper, fetch } = await render();
    await wrapper.get('#search-register').setValue('controlled'); await flushPromises();
    await wrapper.get('#document-search').setValue('ຄວາມປອດໄພ');
    await wrapper.get('#search-revision').setValue('R00');
    await wrapper.get('#search-physical').setValue('Box A');
    await wrapper.get('#search-exact').setValue(true);
    await wrapper.findAll('form')[0].trigger('submit'); await flushPromises();
    const call = fetch.mock.calls.filter(([url]) => !url.includes('/lookups')).at(-1)[0];
    const params = new URL(call, 'http://localhost').searchParams;
    expect(Object.fromEntries(params)).toMatchObject({ company_id: '4', site_id: '7', register: 'controlled', revision: 'R00', physical: 'Box A', search: 'ຄວາມປອດໄພ', exact: '1', page: '1' });
    await wrapper.findAll('button').find(button => button.text() === en.uiNext).trigger('click'); await flushPromises();
    expect(fetch.mock.calls.at(-1)[0]).toContain('page=2');
});
it('clears all rows when workspace access is revoked', async () => {
    const { wrapper, workspace } = await render();
    workspace.revoke(); await flushPromises();
    expect(wrapper.text()).not.toContain('COPY/001'); expect(wrapper.find('table').exists()).toBe(false);
});
it.each([422, 403])('shows validation or access failure without rows for %s', async status => {
    const { wrapper } = await render('en', status);
    expect(wrapper.find('[role="alert"]').exists()).toBe(true);
    expect(wrapper.text()).not.toContain('COPY/001');
});
it('shows loading and cancels stale responses after a workspace change', async () => {
    const { wrapper, fetch, workspace } = await render();
    let resolve;
    fetch.mockImplementationOnce(() => new Promise(done => { resolve = done; }));
    await wrapper.findAll('form')[0].trigger('submit');
    expect(wrapper.find('[aria-busy="true"]').exists()).toBe(true);
    workspace.revoke(); await flushPromises();
    resolve(response({ data: [record], meta: { total: 1 } })); await flushPromises();
    expect(wrapper.text()).not.toContain('COPY/001'); expect(wrapper.find('table').exists()).toBe(false);
});

it('shows the shared empty state when no authorized records match', async () => {
    const { wrapper, fetch } = await render();
    fetch.mockImplementationOnce(async () => response({ data: [], meta: { total: 0 } }));
    await wrapper.findAll('form')[0].trigger('submit'); await flushPromises();
    expect(wrapper.text()).toContain(en.uiEmpty); expect(wrapper.findAll('tbody a')).toHaveLength(0);
});
