import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import { defineComponent } from 'vue';
import DashboardPage from '../../resources/js/pages/DashboardPage.vue';
import { createWorkspace, provideWorkspace } from '../../resources/js/utilities/workspace.js';
import { createLocalization } from '../../resources/js/localization/index.js';
import en from '../../resources/js/localization/en.js';
import lo from '../../resources/js/localization/lo.js';

const context = { key: '2:site', company_id: 1, site_id: 2, department_id: null, permissions: [], company: { name_en: 'Company', name_lo: 'ບໍລິສັດ' }, site: { name_en: 'Site', name_lo: 'ສະຖານທີ່' } };
enableAutoUnmount(afterEach);
beforeEach(() => localStorage.clear());
afterEach(() => { vi.unstubAllGlobals(); });
function render(language = 'en') {
    const workspace = createWorkspace(1, '', 'token');
    workspace.contexts.value = [context]; workspace.siteKey.value = '1:2';
    const wrapper = mount(defineComponent({
        components: { DashboardPage },
        setup() { provideWorkspace(workspace); },
        template: '<DashboardPage :navigation="[]" :context="{}" endpoint="/dashboard-data" csrf-token="token" />',
    }), { global: { plugins: [createLocalization(language)] } });
    return { wrapper, workspace };
}
it('shows authorized totals, escaped recent links and explicit empty due state in Lao', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, status: 200, json: async () => ({ totals: { incoming: 1, outgoing: 0, overdue: 0, due_soon: 0 }, due: [], recent: [{ id: 1, kind: 'incoming', subject: '<script>secret</script>', date: '2026-04-03', status: 'draft', href: '/incoming?record=1' }], quick_actions: [] }) })));
    const { wrapper } = render('lo'); await flushPromises();
    expect(wrapper.text()).toContain(lo.documentOverview);
    expect(wrapper.text()).toContain(lo.uiEmpty);
    expect(wrapper.text()).toContain('03/04/2026');
    expect(wrapper.find('script').exists()).toBe(false);
    expect(wrapper.get('a').attributes('href')).toBe('/incoming?record=1');
    expect(wrapper.text()).not.toContain('overview-');
});
it('never presents request failures as zero totals or empty results', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, status: 500, json: async () => ({}) })));
    const { wrapper } = render(); await flushPromises();
    expect(wrapper.text()).toContain(en.rbacRequestFailed);
    expect(wrapper.text()).not.toContain(en.uiEmpty);
    expect(wrapper.findAll('p.tabular-nums').map(item => item.text())).toEqual(['—', '—', '—', '—']);
});
it('aborts a previous workspace request and ignores a late sensitive response', async () => {
    let resolve;
    const fetch = vi.fn((url, options) => new Promise(done => { resolve = () => done({ ok: true, status: 200, json: async () => ({ totals: { incoming: 99 }, recent: [{ subject: 'Stale secret' }], due: [] }) }); }));
    vi.stubGlobal('fetch', fetch);
    const { wrapper, workspace } = render();
    workspace.revoke(); await flushPromises();
    expect(fetch.mock.calls[0][1].signal.aborted).toBe(true);
    resolve(); await flushPromises();
    expect(wrapper.text()).not.toContain('Stale secret');
    expect(wrapper.find('table').exists()).toBe(false);
});
