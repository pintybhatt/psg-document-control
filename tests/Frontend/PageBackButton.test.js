import { afterEach, expect, it } from 'vitest';
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import PageBackButton from '../../resources/js/components/ui/PageBackButton.vue';
import { createLocalization } from '../../resources/js/localization/index.js';
import en from '../../resources/js/localization/en.js';
import lo from '../../resources/js/localization/lo.js';

enableAutoUnmount(afterEach);
afterEach(() => document.body.replaceChildren());

function render(props = {}, language = 'en') {
    return mount(PageBackButton, { props, attachTo: document.body, global: { plugins: [createLocalization(language)] } });
}

it('moves into the page header row with a short label, chevron icon and descriptive tooltip', async () => {
    const header = document.createElement('div'); header.setAttribute('data-page-header-actions', ''); document.body.appendChild(header);
    const wrapper = render({ title: 'Back to register · Incoming correspondence register' });
    await flushPromises();
    const button = header.querySelector('[data-page-back]');
    expect(button).not.toBeNull();
    expect(button.textContent.trim()).toBe(en.uiBack);
    expect(button.getAttribute('title')).toBe('Back to register · Incoming correspondence register');
    expect(button.querySelector('[data-button-icon]')).not.toBeNull();
    button.click();
    expect(wrapper.emitted('click')).toHaveLength(1);
});

it('renders in place when there is no page header and translates the label', async () => {
    const wrapper = render({}, 'lo');
    await flushPromises();
    expect(wrapper.get('[data-page-back]').text()).toBe(lo.uiBack);
    expect(wrapper.get('[data-page-back]').attributes('title')).toBe(lo.backToRegister);
});
