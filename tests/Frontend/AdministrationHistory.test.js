import { afterEach, describe, expect, it, vi } from 'vitest';
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import AdministrationHistory from '../../resources/js/components/AdministrationHistory.vue';
import LanguageSwitcher from '../../resources/js/components/LanguageSwitcher.vue';
import { createLocalization } from '../../resources/js/localization/index.js';

const scope = { scope_type: 'site', scope_key: 'site:5', company_id: 2, site_id: 5, permissions: ['security.audit.view', 'master-data.view', 'numbering.view'] };
const event = { id: 1, timestamp: '2026-10-27T03:00:00Z', actor: '<script>Example</script>', action: 'updated', subject_type: 'categories', subject_id: 8, changes: [{ field: 'active', before: true, after: false }] };
enableAutoUnmount(afterEach);
afterEach(() => { document.body.replaceChildren(); localStorage.clear(); vi.unstubAllGlobals(); });
function render() {
    return mount({ components: { AdministrationHistory, LanguageSwitcher }, setup: () => ({ scope }), template: '<div><LanguageSwitcher /><AdministrationHistory :scope="scope" endpoint="/history" csrf-token="test" /></div>' }, { attachTo: document.body, global: { plugins: [createLocalization()] } });
}
function reply(data) { return { ok: true, status: 200, json: async () => data }; }
describe('Administration configuration history', () => {
    it('loads scoped history, formats Laos timestamps, escapes names and switches language', async () => {
        const fetch = vi.fn(async () => reply({ data: [event], meta: { total: 11 } }));
        vi.stubGlobal('fetch', fetch);
        const wrapper = render();
        await flushPromises();
        expect(fetch.mock.calls[0][0]).toContain('scope_type=site&source=master-data&page=1&per_page=10&company_id=2&site_id=5');
        expect(wrapper.text()).toContain('27/10/2026, 10:00');
        expect(wrapper.text()).toContain('Inactive');
        expect(wrapper.find('script').exists()).toBe(false);
        expect(wrapper.findAll('select').at(-1).findAll('option').map(item => item.element.value)).toEqual(['master-data', 'numbering']);
        await wrapper.get('[data-language-switcher] input[value="lo"]').setValue(true);
        expect(wrapper.text()).toContain('ປະຫວັດການຕັ້ງຄ່າ');
        expect(wrapper.text()).toContain('ປິດການໃຊ້ງານ');
        expect(wrapper.text()).not.toContain('adminSubject-');
        await wrapper.findAll('button').find(item => item.text() === 'ຖັດໄປ').trigger('click');
        await flushPromises();
        expect(fetch.mock.calls.at(-1)[0]).toContain('page=2');
        await wrapper.findAll('select').at(-1).setValue('numbering');
        await flushPromises();
        expect(fetch.mock.calls.at(-1)[0]).toContain('source=numbering&page=1');
    });

    it('discards pending foreign-scope responses and clears history on server denial', async () => {
        let resolve;
        const fetch = vi.fn().mockImplementationOnce(() => new Promise(done => { resolve = done; }))
            .mockResolvedValue({ ok: false, status: 403, json: async () => ({ message: 'rbacForbidden' }) });
        vi.stubGlobal('fetch', fetch);
        const wrapper = mount(AdministrationHistory, { props: { scope, endpoint: '/history', csrfToken: 'test' }, global: { plugins: [createLocalization()] } });
        await wrapper.setProps({ scope: { ...scope, scope_key: 'site:6', site_id: 6 } });
        await flushPromises();
        resolve(reply({ data: [event], meta: { total: 1 } }));
        await flushPromises();
        expect(wrapper.text()).not.toContain('Example');
        expect(wrapper.emitted('unauthorized')).toHaveLength(1);
    });
});

it('uses bilingual review-policy descriptions and translates the recorded calculation baseline', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => reply({ data: [{ ...event, subject_type: 'types', event_en: 'Document review policy changed', event_lo: 'ປ່ຽນນະໂຍບາຍທົບທວນເອກະສານ', changes: [{ field: 'review_baseline', before: null, after: 'completion_date' }, { field: 'review_enabled', before: false, after: true }] }], meta: { total: 1 } })));
    const wrapper = render(); await flushPromises();
    expect(wrapper.text()).toContain('Document review policy changed');
    expect(wrapper.text()).toContain('Review completion date');
    expect(wrapper.text()).not.toContain('completion_date');
    await wrapper.get('[data-language-switcher] input[value="lo"]').setValue(true);
    expect(wrapper.text()).toContain('ປ່ຽນນະໂຍບາຍທົບທວນເອກະສານ');
    expect(wrapper.text()).toContain('ວັນສຳເລັດການທົບທວນ');
});
