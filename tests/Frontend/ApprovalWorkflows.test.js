import { afterEach, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import ApprovalWorkflows from '../../resources/js/components/ApprovalWorkflows.vue';
import AdministrationPage from '../../resources/js/pages/AdministrationPage.vue';
import { createLocalization } from '../../resources/js/localization/index.js';
import en from '../../resources/js/localization/en.js';
import lo from '../../resources/js/localization/lo.js';

const scope = { scope_key: 'site:7', scope_type: 'site', company_id: 4, site_id: 7, permissions: ['master-data.view', 'master-data.manage', 'roles.view'] };
const role = { id: 12, code: 'REVIEWER', name_en: '<script>Reviewer</script>', name_lo: 'ຜູ້ທົບທວນ', scope_key: 'site:7' };
const rows = () => ['controlled', 'incoming', 'outgoing'].map(register_key => ({ register_key, version: 0, enabled: false, sequence: 'sequential', levels: [] }));
const response = (body, status = 200) => ({ ok: status < 400, status, json: async () => body });
const stubs = { ConfirmDialog: { props: ['modelValue', 'message'], emits: ['confirm', 'cancel'], template: '<section v-if="modelValue" role="dialog"><p>{{ message }}</p><button data-confirm @click="$emit(\'confirm\')">Confirm</button></section>' } };
let wrappers = [];
afterEach(() => { wrappers.forEach(wrapper => wrapper.unmount()); wrappers = []; vi.unstubAllGlobals(); document.body.innerHTML = ''; });
function server(data = rows()) {
    const fetch = vi.fn(async (url, options = {}) => {
        const path = new URL(url, 'http://localhost').pathname;
        if (path === '/contexts') { return response({ data: [scope] }); }
        if (path === '/roles' || path === '/permissions') { return response({ data: [], meta: { total: 0 } }); }
        if (path.endsWith('/history')) { return response({ data: [{ ...data[0], created_by: 5, created_at: '2026-10-28T12:00:00Z', change_reason: '<b>Reviewed</b>' }], total: 1 }); }
        if (path.endsWith('/roles')) { return response({ data: [role, { ...role, id: 13, code: 'SECOND' }] }); }
        if (options.method === 'POST') {
            const body = JSON.parse(options.body);
            Object.assign(data.find(row => row.register_key === path.split('/').at(-1)), { enabled: body.enabled, version: body.expected_version + 1, levels: body.role_ids.map((id, index) => ({ level: index + 1, role: { ...role, id } })) });
            return response({ data: {} }, 201);
        }
        return response({ data, enforcement_enabled: false });
    });
    vi.stubGlobal('fetch', fetch); return fetch;
}
async function render(current = scope, language = 'en') {
    const wrapper = mount(ApprovalWorkflows, { props: { scope: structuredClone(current), endpoint: '/workflows', csrfToken: 'csrf-test' }, global: { plugins: [createLocalization(language)], stubs }, attachTo: document.body });
    wrappers.push(wrapper); await flushPromises(); return wrapper;
}
function button(wrapper, label) { const match = wrapper.findAll('button').find(item => item.text() === label); expect(match).toBeDefined(); return match; }

it.each(['en', 'lo'])('shows disabled settings for three registers and a configuration-only notice in %s', async language => {
    server(); const wrapper = await render(scope, language); const text = language === 'lo' ? lo : en;
    expect(wrapper.text()).toContain(text.workflowNotice);
    expect(wrapper.text()).toContain(text.workflowDisabled);
    expect(wrapper.text()).toContain(text.workflowSequential);
    expect(wrapper.findAll('h4')).toHaveLength(3);
    expect(wrapper.text()).not.toMatch(/workflowTitle|workflowEnabled/);
});

it('saves explicit sequential levels with scope, CSRF and an optimistic version after confirmation', async () => {
    const fetch = server(); const wrapper = await render();
    await button(wrapper, en.rbacEdit).trigger('click');
    await wrapper.get('#workflow-enabled').setValue(true);
    await wrapper.get('#workflow-count').setValue('2');
    await wrapper.get('#workflow-role-1').setValue('12');
    await wrapper.get('#workflow-role-2').setValue('13');
    await wrapper.get('#workflow-reason').setValue('Synthetic approved configuration');
    await wrapper.get('form').trigger('submit');
    expect(fetch.mock.calls.some(([, options]) => options.method === 'POST')).toBe(false);
    await wrapper.get('[data-confirm]').trigger('click'); await flushPromises();
    const [url, options] = fetch.mock.calls.find(([, options]) => options.method === 'POST');
    expect(url).toBe('/workflows/controlled');
    expect(options.headers['X-CSRF-TOKEN']).toBe('csrf-test');
    expect(JSON.parse(options.body)).toEqual({ scope_type: 'site', company_id: 4, site_id: 7, expected_version: 0, enabled: true, sequence: 'sequential', role_ids: [12, 13], change_reason: 'Synthetic approved configuration' });
    expect(wrapper.text()).toContain(en.workflowSaved);
    expect(wrapper.find('script').exists()).toBe(false);
    expect(wrapper.text()).toContain('<script>Reviewer</script>');
});

it('can disable an existing workflow with zero levels and preserves readable configuration history', async () => {
    const initial = rows();
    initial[0] = { ...initial[0], version: 2, enabled: true, levels: [{ level: 1, role }] };
    const fetch = server(initial); const wrapper = await render();
    await button(wrapper, en.rbacEdit).trigger('click');
    await wrapper.get('#workflow-enabled').setValue(false);
    await wrapper.get('#workflow-count').setValue('0');
    await wrapper.get('#workflow-reason').setValue('Disabled configuration');
    await wrapper.get('form').trigger('submit'); await wrapper.get('[data-confirm]').trigger('click'); await flushPromises();
    expect(JSON.parse(fetch.mock.calls.find(([, options]) => options.method === 'POST')[1].body)).toMatchObject({ expected_version: 2, enabled: false, role_ids: [] });
    await button(wrapper, en.workflowHistory).trigger('click'); await flushPromises();
    expect(wrapper.text()).toContain('<b>Reviewed</b>');
    expect(wrapper.find('b').exists()).toBe(false);
    expect(fetch.mock.calls.some(([url]) => url.includes('/controlled/history?') && url.includes('site_id=7'))).toBe(true);
});

it('permits history viewing without mutation controls or role lookups for read-only administrators', async () => {
    const fetch = server(); const wrapper = await render({ ...scope, permissions: ['master-data.view'] });
    expect(wrapper.findAll('button').some(item => item.text() === en.rbacEdit)).toBe(false);
    expect(fetch.mock.calls.some(([url]) => url.includes('/roles'))).toBe(false);
    await button(wrapper, en.workflowHistory).trigger('click'); await flushPromises();
    expect(wrapper.find('form').exists()).toBe(false);
});

it('shows a stale-version error and keeps the draft instead of silently overwriting settings', async () => {
    const fetch = server(); const wrapper = await render();
    await button(wrapper, en.rbacEdit).trigger('click');
    await wrapper.get('#workflow-reason').setValue('Stale save');
    await wrapper.get('form').trigger('submit');
    fetch.mockImplementationOnce(async () => response({ message: 'Invalid', errors: { expected_version: ['workflowStale'] } }, 422));
    await wrapper.get('[data-confirm]').trigger('click'); await flushPromises();
    expect(wrapper.text()).toContain(en.workflowStale);
    expect(wrapper.get('#workflow-reason').element.value).toBe('Stale save');
    expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
});

it('clears settings and ignores a late response across scope changes and access revocation', async () => {
    const fetch = server(); const wrapper = await render();
    let resolve;
    fetch.mockImplementationOnce(() => new Promise(done => { resolve = done; }));
    await button(wrapper, en.workflowHistory).trigger('click');
    await wrapper.setProps({ scope: { ...scope, scope_key: 'site:8', site_id: 8 } });
    resolve(response({ data: [{ ...rows()[0], levels: [{ level: 1, role }], change_reason: 'Old scope secret' }], total: 1 }));
    await flushPromises();
    expect(wrapper.text()).not.toContain('Old scope secret');
    fetch.mockImplementationOnce(async () => response({ message: 'Forbidden' }, 403));
    await wrapper.setProps({ scope: { ...scope, scope_key: 'site:9', site_id: 9 } }); await flushPromises();
    expect(wrapper.findAll('h4')).toHaveLength(0);
    expect(wrapper.emitted('unauthorized')).toHaveLength(1);
});

it('adds Approval Workflows to the existing Administration navigation', async () => {
    server(); const wrapper = mount(AdministrationPage, { props: { endpoints: { contexts: '/contexts', roles: '/roles', permissions: '/permissions', approvalWorkflows: '/workflows' }, csrfToken: 'csrf-test' }, global: { plugins: [createLocalization()], stubs } });
    wrappers.push(wrapper); await flushPromises();
    await button(wrapper, en.workflowTitle).trigger('click'); await flushPromises();
    expect(wrapper.findComponent(ApprovalWorkflows).exists()).toBe(true);
    expect(wrapper.text()).toContain(en.workflowNotice);
});
