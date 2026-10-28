import { afterEach, expect, it, vi } from 'vitest';
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import TemplateCatalogue from '../../resources/js/components/TemplateCatalogue.vue';
import AdministrationPage from '../../resources/js/pages/AdministrationPage.vue';
import { createLocalization } from '../../resources/js/localization/index.js';
import en from '../../resources/js/localization/en.js';
import lo from '../../resources/js/localization/lo.js';

const scope = { scope_key: 'site:7', scope_type: 'site', company_id: 4, site_id: 7, permissions: ['templates.view', 'templates.manage'] };
const entry = { id: 1, name_en: '<script>Synthetic template</script>', name_lo: 'ແບບທົດສອບ', purpose: 'Synthetic purpose', attachment_version_id: 'version-a', status: 'inactive' };
const response = (body, status = 200) => ({ ok: status < 400, status, json: async () => structuredClone(body) });
enableAutoUnmount(afterEach);
afterEach(() => { document.body.replaceChildren(); localStorage.clear(); vi.unstubAllGlobals(); });
function server() {
    const rows = [structuredClone(entry)];
    const history = [{ id: 1, actor_id: 5, action: 'created', created_at: '2026-10-28T10:00:00Z' }];
    const fetch = vi.fn(async (url, options = {}) => {
        const path = new URL(url, 'http://localhost').pathname;
        if (path.endsWith('/sources')) { return response({ data: [{ id: 'version-a', original_name: 'synthetic.docx', document_code: 'TEST/SOP/001', revision: 'R00', version: 1 }] }); }
        if (path.endsWith('/retire')) {
            rows[0].status = 'retired'; history.push({ id: 2, actor_id: 5, action: 'retired', reason: JSON.parse(options.body).reason, created_at: '2026-10-28T11:00:00Z' });
            return response({ data: rows[0] });
        }
        if (options.method === 'POST') { rows.push({ ...JSON.parse(options.body), id: 2, status: 'inactive' }); return response({ data: rows[1] }, 201); }
        if (path === '/templates') { return response({ data: rows, meta: { total: rows.length }, publication_enabled: false }); }
        return response({ data: rows.find(row => String(row.id) === path.split('/').at(-1)), history, publication_enabled: false });
    });
    vi.stubGlobal('fetch', fetch);
    return fetch;
}
async function render(currentScope = scope, language = 'en') {
    const wrapper = mount(TemplateCatalogue, { props: { scope: structuredClone(currentScope), endpoint: '/templates', csrfToken: 'sample-csrf' }, attachTo: document.body,
        global: { plugins: [createLocalization(language)], stubs: { ConfirmDialog: { props: ['modelValue', 'message'], emits: ['confirm', 'cancel'], template: '<section v-if="modelValue" role="dialog"><p>{{ message }}</p><button data-confirm @click="$emit(\'confirm\')">Confirm</button></section>' } } } });
    await flushPromises(); return wrapper;
}
function button(wrapper, label) { const found = wrapper.findAll('button').find(row => row.text() === label); expect(found).toBeDefined(); return found; }

it.each(['en', 'lo'])('shows an explicitly inactive catalogue without publication or download controls in %s', async language => {
    server(); const wrapper = await render(scope, language); const text = language === 'lo' ? lo : en;
    expect(wrapper.text()).toContain(text.templateInactiveNotice);
    expect(wrapper.text()).toContain(text['templateStatus-inactive']);
    expect(wrapper.find('a[download]').exists()).toBe(false);
    expect(wrapper.find('script').exists()).toBe(false);
    expect(wrapper.text()).not.toMatch(/templateTitle|templateStatus-/);
});

it('creates an inactive candidate using an authorized source choice and exact scope with CSRF', async () => {
    const fetch = server(); const wrapper = await render();
    await button(wrapper, en.templateCreate).trigger('click');
    await wrapper.get('#template-name-en').setValue('Synthetic candidate');
    await wrapper.get('#template-name-lo').setValue('ແບບທົດສອບ');
    await wrapper.get('#template-purpose').setValue('Synthetic purpose');
    await wrapper.get('#template-source-search').setValue('TEST/SOP');
    await button(wrapper, en.templateFindSource).trigger('click'); await flushPromises();
    expect(wrapper.text()).toContain('TEST/SOP/001 · R00 · synthetic.docx');
    await wrapper.get('#template-source').setValue('version-a');
    await wrapper.find('form').trigger('submit'); await flushPromises();
    const options = fetch.mock.calls.find(([, options]) => options.method === 'POST')[1];
    expect(options.headers['X-CSRF-TOKEN']).toBe('sample-csrf');
    expect(JSON.parse(options.body)).toMatchObject({ scope_type: 'site', company_id: 4, site_id: 7, name_en: 'Synthetic candidate', attachment_version_id: 'version-a' });
    expect(JSON.parse(options.body)).not.toHaveProperty('status');
    expect(wrapper.text()).toContain(en.templateSourceReferenced);
});

it('confirms retirement and preserves visible history without offering the entry for use', async () => {
    const fetch = server(); const wrapper = await render();
    await button(wrapper, en.rbacDetails).trigger('click'); await flushPromises();
    expect(wrapper.text()).toContain('<script>Synthetic template</script>');
    expect(wrapper.find('script').exists()).toBe(false);
    await wrapper.get('#template-retire-reason').setValue('Candidate withdrawn');
    await wrapper.find('form').trigger('submit'); await flushPromises();
    expect(fetch.mock.calls.some(([url]) => url.includes('/retire'))).toBe(false);
    await wrapper.get('[data-confirm]').trigger('click'); await flushPromises();
    expect(JSON.parse(fetch.mock.calls.find(([url]) => url.includes('/retire'))[1].body)).toMatchObject({ reason: 'Candidate withdrawn', site_id: 7 });
    expect(wrapper.text()).toContain(en['templateHistory-retired']);
    expect(wrapper.find('#template-retire-reason').exists()).toBe(false);
});

it('keeps a read-only manager view free of mutation controls', async () => {
    server(); const wrapper = await render({ ...scope, permissions: ['templates.view'] });
    expect(wrapper.findAll('button').some(row => row.text() === en.templateCreate)).toBe(false);
    await button(wrapper, en.rbacDetails).trigger('click'); await flushPromises();
    expect(wrapper.find('form').exists()).toBe(false);
});

it('integrates with Administration for a site-scoped template manager', async () => {
    const fetch = vi.fn(async url => url.startsWith('/contexts') ? response({ data: [scope] }) : response({ data: [], meta: { total: 0 }, publication_enabled: false }));
    vi.stubGlobal('fetch', fetch);
    const wrapper = mount(AdministrationPage, { props: { endpoints: { contexts: '/contexts', templates: '/templates' }, csrfToken: 'sample-csrf' }, global: { plugins: [createLocalization()], stubs: { ConfirmDialog: true } } });
    await flushPromises();
    expect(wrapper.findComponent(TemplateCatalogue).exists()).toBe(true);
    expect(wrapper.text()).toContain(en.templateInactiveNotice);
    expect(fetch.mock.calls.some(([url]) => url.includes('/templates?') && url.includes('site_id=7'))).toBe(true);
});

it('clears confidential metadata and ignores a late response when scope changes or access is revoked', async () => {
    const fetch = server(); const wrapper = await render();
    let resolve;
    fetch.mockImplementationOnce(() => new Promise(done => { resolve = done; }));
    await button(wrapper, en.rbacDetails).trigger('click');
    await wrapper.setProps({ scope: { ...scope, scope_key: 'site:8', site_id: 8 } });
    fetch.mockImplementationOnce(async () => response({ message: 'Forbidden' }, 403));
    resolve(response({ data: entry, history: [] })); await flushPromises();
    expect(wrapper.find('#template-retire-reason').exists()).toBe(false);
    await wrapper.setProps({ scope: { ...scope, scope_key: 'site:9', site_id: 9 } }); await flushPromises();
    expect(wrapper.text()).not.toContain('<script>Synthetic template</script>');
    expect(wrapper.emitted('unauthorized')).toHaveLength(1);
});
