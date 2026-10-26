import { afterAll, afterEach, beforeAll, beforeEach, expect, it, vi } from 'vitest';
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import { ref } from 'vue';
import PdfPreviewDialog from '../../resources/js/components/ui/PdfPreviewDialog.vue';
import LanguageSwitcher from '../../resources/js/components/LanguageSwitcher.vue';
import { createLocalization, useLocalization } from '../../resources/js/localization/index.js';
import lo from '../../resources/js/localization/lo.js';

const descriptors = Object.getOwnPropertyDescriptors(HTMLDialogElement.prototype);
enableAutoUnmount(afterEach);
beforeAll(() => Object.defineProperties(HTMLDialogElement.prototype, {
    showModal: { configurable: true, value() { this.setAttribute('open', ''); } },
    close: { configurable: true, value() { if (this.open) { this.removeAttribute('open'); this.dispatchEvent(new Event('close')); } } },
}));
afterAll(() => { for (const key of ['showModal', 'close']) { if (descriptors[key]) { Object.defineProperty(HTMLDialogElement.prototype, key, descriptors[key]); } else { delete HTMLDialogElement.prototype[key]; } } });
beforeEach(() => {
    localStorage.clear();
    vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, status: 200, headers: { get: () => '3' }, blob: async () => new Blob(['%PDF-test'], { type: 'application/pdf' }) })));
    vi.stubGlobal('URL', class extends URL { static createObjectURL = vi.fn(() => 'blob:authorized-report'); static revokeObjectURL = vi.fn(); });
});
afterEach(() => { vi.unstubAllGlobals(); vi.restoreAllMocks(); document.body.replaceChildren(); });
function render() {
    return mount({
        components: { PdfPreviewDialog, LanguageSwitcher },
        props: { url: { type: String, default: '/incoming/export?format=pdf' } },
        setup() { const open = ref(false); return { open, ...useLocalization() }; },
        template: '<div><button id="open" @click="open = true">Open</button><LanguageSwitcher /><PdfPreviewDialog v-model="open" :url="url" csrf-token="token" filename="incoming-correspondence.pdf" :title="t(\'reportPreviewPdf\')" /></div>',
    }, { attachTo: document.body, global: { plugins: [createLocalization()] } });
}
it('previews PDF in the dialog with paging, zoom, direct download and translated controls', async () => {
    const wrapper = render();
    const opener = wrapper.get('#open'); opener.element.focus(); await opener.trigger('click'); await flushPromises();
    expect(wrapper.get('dialog').element.open).toBe(true);
    expect(wrapper.get('iframe').attributes('src')).toContain('blob:authorized-report#page=1');
    expect(wrapper.text()).toContain('Page 1 of 3');
    const buttons = () => wrapper.findAll('button');
    await buttons().find(item => item.text() === 'Next').trigger('click');
    expect(wrapper.get('iframe').attributes('src')).toContain('page=2');
    await wrapper.get('nav select').setValue('150');
    expect(wrapper.get('iframe').attributes('src')).toContain('zoom=150');
    const download = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function () { expect(this.download).toBe('incoming-correspondence.pdf'); expect(this.href).toBe('blob:authorized-report'); });
    await buttons().find(item => item.text() === 'Download PDF').trigger('click');
    expect(download).toHaveBeenCalledOnce();
    expect(fetch).toHaveBeenCalledOnce();
    await wrapper.get('select[aria-label="Language"]').setValue('lo');
    expect(wrapper.text()).toContain(lo.reportDownloadPdf); expect(wrapper.text()).toContain(lo.reportZoom);
    await wrapper.get('dialog').trigger('cancel'); await flushPromises();
    expect(wrapper.get('dialog').element.open).toBe(false);
    expect(wrapper.find('iframe').exists()).toBe(false);
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:authorized-report');
    expect(document.activeElement).toBe(opener.element);
});
it('shows authorization errors without rendering a PDF or allowing downloads', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, status: 403, json: async () => ({ message: 'rbacForbidden' }) })));
    const wrapper = render(); await wrapper.get('#open').trigger('click'); await flushPromises();
    expect(wrapper.get('[role="alert"]').text()).toBeTruthy();
    expect(wrapper.find('iframe').exists()).toBe(false);
    expect(URL.createObjectURL).not.toHaveBeenCalled();
    expect(wrapper.findComponent(PdfPreviewDialog).emitted('failure')[0][0].status).toBe(403);
});
it('aborts old scope requests and ignores late PDF responses after closing', async () => {
    let finish;
    vi.stubGlobal('fetch', vi.fn((url, options) => new Promise(resolve => { finish = () => resolve({ ok: true, status: 200, headers: { get: () => '1' }, blob: async () => new Blob(['old secret'], { type: 'application/pdf' }) }); })));
    const wrapper = render(); await wrapper.get('#open').trigger('click'); await flushPromises();
    const signal = fetch.mock.calls[0][1].signal;
    await wrapper.get('dialog').trigger('cancel'); await flushPromises();
    expect(signal.aborted).toBe(true);
    finish(); await flushPromises();
    expect(URL.createObjectURL).not.toHaveBeenCalled(); expect(wrapper.find('iframe').exists()).toBe(false);
});
