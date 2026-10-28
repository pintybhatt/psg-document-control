import { afterEach, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import DocumentApprovalPanel from '../../resources/js/components/DocumentApprovalPanel.vue';
import { createLocalization } from '../../resources/js/localization/index.js';
import en from '../../resources/js/localization/en.js';
import lo from '../../resources/js/localization/lo.js';

const scope = { unit_key: 'site:7', company_id: 4, site_id: 7, department_id: null };
const base = { status: 'pending', configuration_version: 2, current_level: 1, content_hash: 'a'.repeat(64), expected_event_id: 5, lock_version: 8,
    levels: [{ level: 1, role: { id: 11, code: 'REVIEWER', name_en: 'Reviewer', name_lo: 'ຜູ້ທົບທວນ' } }], can_submit: false, can_decide: true };
const response = (body, status = 200) => ({ ok: status < 400, status, json: async () => body });
let wrappers = [];
afterEach(() => { wrappers.forEach(wrapper => wrapper.unmount()); wrappers = []; vi.unstubAllGlobals(); document.body.innerHTML = ''; });
function server(state = base) {
    const fetch = vi.fn(async (url, options = {}) => options.method === 'POST' ? response({ saved: true }) : response({ data: structuredClone(state), history: [{ id: 1, submission_id: 2, configuration_version: 2, level: 0, decision: 'submitted', actor_id: 3, recorded_at: '2026-10-28T10:00:00Z', comments: '<script>evidence</script>' }] }));
    vi.stubGlobal('fetch', fetch); return fetch;
}
async function render(kind = 'outgoing', language = 'en') {
    const wrapper = mount(DocumentApprovalPanel, { props: { execution: { url: '/approvals/outgoing/3', revision_id: kind === 'controlled' ? 10 : null }, scope, csrfToken: 'test-csrf', kind }, global: { plugins: [createLocalization(language)] } });
    wrappers.push(wrapper); await flushPromises(); return wrapper;
}
function button(wrapper, label) { const found = wrapper.findAll('button').find(item => item.text() === label); expect(found).toBeDefined(); return found; }

it.each(['en', 'lo'])('displays the pinned version, current level, history and escaped comments in %s', async language => {
    server(); const wrapper = await render('outgoing', language); const labels = language === 'lo' ? lo : en;
    expect(wrapper.text()).toContain(labels['approvalStatus-pending']);
    expect(wrapper.text()).toContain(labels.workflowVersion + ' 2');
    expect(wrapper.text()).toContain('<script>evidence</script>');
    expect(wrapper.find('script').exists()).toBe(false);
    expect(wrapper.text()).not.toMatch(/approvalStatus-|approvalExecutionTitle/);
});

it.each(['approved', 'rejected'])('records an eligible %s decision with server tokens, comments, scope and CSRF', async decision => {
    const fetch = server(); const wrapper = await render();
    await wrapper.get('#approval-comments').setValue('Reviewed exact content');
    await button(wrapper, decision === 'approved' ? en.approvalApprove : en.approvalReject).trigger('click'); await flushPromises();
    const options = fetch.mock.calls.find(([, options]) => options.method === 'POST')[1];
    expect(options.headers['X-CSRF-TOKEN']).toBe('test-csrf');
    expect(JSON.parse(options.body)).toMatchObject({ company_id: 4, site_id: 7, decision, comments: 'Reviewed exact content', content_hash: base.content_hash, expected_event_id: 5 });
    expect(wrapper.emitted('saved')).toHaveLength(1);
});

it('offers resubmission only when the server permits it and sends the C1 lock version', async () => {
    const fetch = server({ ...base, status: 'rejected', current_level: null, can_submit: true, can_decide: false });
    const wrapper = await render('controlled');
    expect(wrapper.text()).not.toContain(en.approvalApprove);
    await button(wrapper, en.approvalSubmit).trigger('click'); await flushPromises();
    expect(JSON.parse(fetch.mock.calls.find(([, options]) => options.method === 'POST')[1].body)).toMatchObject({ decision: 'submitted', revision_id: 10, lock_version: 8 });
    expect(wrapper.text()).not.toContain('<script>evidence</script>');
});

it('does not offer decisions to ineligible users and identifies the unresolved incoming checkpoint', async () => {
    server({ ...base, can_submit: false, can_decide: false }); const wrapper = await render('incoming');
    expect(wrapper.find('#approval-comments').exists()).toBe(false);
    expect(wrapper.text()).toContain(en.approvalIncomingPending);
    expect(wrapper.text()).not.toContain(en.approvalApprove);
});

it('preserves comments on stale approval and supports an explicit refresh', async () => {
    const fetch = server(); const wrapper = await render();
    await wrapper.get('#approval-comments').setValue('Keep this review');
    fetch.mockImplementationOnce(async () => response({ errors: { approval: ['approvalStale'] } }, 422));
    await button(wrapper, en.approvalApprove).trigger('click'); await flushPromises();
    expect(wrapper.text()).toContain(en.approvalStale);
    expect(wrapper.get('#approval-comments').element.value).toBe('Keep this review');
    await button(wrapper, en.approvalRefresh).trigger('click'); await flushPromises();
    expect(wrapper.get('#approval-comments').element.value).toBe('');
});

it('clears history on revoked access and ignores late results from a previous scope', async () => {
    const fetch = server(); const wrapper = await render();
    let resolve;
    fetch.mockImplementationOnce(() => new Promise(done => { resolve = done; }));
    await button(wrapper, en.approvalRefresh).trigger('click');
    await wrapper.setProps({ execution: { url: '/approvals/outgoing/9', revision_id: null }, scope: { ...scope, unit_key: 'site:8', site_id: 8 } }); await flushPromises();
    resolve(response({ data: base, history: [{ comments: 'Old secret' }] })); await flushPromises();
    expect(wrapper.text()).not.toContain('Old secret');
    fetch.mockImplementationOnce(async () => response({ message: 'Denied' }, 403));
    await button(wrapper, en.approvalRefresh).trigger('click'); await flushPromises();
    expect(wrapper.text()).not.toContain('<script>evidence</script>');
    expect(wrapper.emitted('failure')).toHaveLength(1);
});
