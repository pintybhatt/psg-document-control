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
function render(language = 'en', initialContext = context) {
    const workspace = createWorkspace(1, '/contexts', 'token');
    if (initialContext) {
        workspace.contexts.value = [initialContext]; workspace.siteKey.value = '1:2';
    }
    const wrapper = mount(defineComponent({
        components: { DashboardPage },
        setup() { provideWorkspace(workspace); },
        template: '<DashboardPage :navigation="[]" :context="{}" endpoint="/dashboard-data" csrf-token="token" />',
    }), { global: { plugins: [createLocalization(language)] } });
    return { wrapper, workspace };
}
it('selects and switches between eligible company sites and clears the dashboard after revocation', async () => {
    const sibling = { ...context, key: '3:site', site_id: 3, site: { name_en: 'Other Site', name_lo: 'ສະຖານທີ່ສອງ' } };
    let contexts = [context, sibling];
    const fetch = vi.fn(async url => ({ ok: true, status: 200, json: async () => url === '/contexts'
        ? { data: contexts }
        : { totals: { incoming: 1, outgoing: 0, overdue: 0, due_soon: 0 }, recent: [], due: [] } }));
    vi.stubGlobal('fetch', fetch);
    const { wrapper, workspace } = render('lo', null);
    await workspace.refresh(); await flushPromises();
    expect(workspace.sites.value.map(site => site.site_id)).toEqual([2, 3]);
    expect(wrapper.get('[data-dashboard-hero]').text()).toContain(context.site.name_lo);
    expect(fetch).toHaveBeenCalledWith('/dashboard-data?company_id=1&site_id=2', expect.any(Object));

    workspace.siteKey.value = '1:3'; await flushPromises();
    expect(wrapper.get('[data-dashboard-hero]').text()).toContain(sibling.site.name_lo);
    expect(fetch).toHaveBeenCalledWith('/dashboard-data?company_id=1&site_id=3', expect.any(Object));

    contexts = [];
    await workspace.refresh(); await flushPromises();
    expect(wrapper.text()).toContain(lo.workspaceNoContexts);
    expect(wrapper.text()).toContain(lo.noSite);
});
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
it('shows controlled reviews and compact operational metrics with Lao labels', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, status: 200, json: async () => ({ totals: { incoming: 3, outgoing: 2, controlled: 1, overdue: 1, open_incoming: 2, open_outgoing: 1, pending_response: 1, closed: 2, due_soon: 1, review_soon: 0, review_overdue: 1, checkout_overdue: 1 }, due: [], recent: [], reviews: [{ number: 'SOP/001', title_en: 'Safety', title_lo: 'ຄວາມປອດໄພ', review_date: '2026-10-26', href: '/controlled-documents?record=1' }], quick_actions: [], trends: { monthly: [], category_en: [{ category_en: 'Safety', category_lo: 'ຄວາມປອດໄພ', total: 1 }], department_en: [] } }) })));
    const { wrapper } = render('lo'); await flushPromises();
    expect(wrapper.text()).toContain(lo['overview-controlled']);
    expect(wrapper.text()).toContain(lo['metric-review_overdue']);
    expect(wrapper.text()).toContain('26/10/2026');
    expect(wrapper.text()).toContain('ຄວາມປອດໄພ');
    expect(wrapper.text()).not.toMatch(/metric-|reportReviewDates|overview-/);
});
