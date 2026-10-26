import { afterEach, expect, it, vi } from 'vitest';
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import TextInput from '../../resources/js/components/ui/TextInput.vue';
import { createLocalization } from '../../resources/js/localization/index.js';
import { calendarDate, laosToday, moveCalendarDay, moveCalendarMonth } from '../../resources/js/utilities/dateCalendar.js';
import en from '../../resources/js/localization/en.js';
import lo from '../../resources/js/localization/lo.js';

enableAutoUnmount(afterEach);
afterEach(() => { localStorage.clear(); vi.useRealTimers(); vi.unstubAllEnvs(); });
function render(props = {}, language = 'en') {
    return mount(TextInput, { props: { id: 'date', type: 'date', label: 'Document date', modelValue: '2026-10-27', ...props }, attachTo: document.body, global: { plugins: [createLocalization(language)] } });
}
async function open(wrapper) { await wrapper.get('button[aria-haspopup="dialog"]').trigger('click'); await flushPromises(); }
const popup = wrapper => wrapper.get('[role="dialog"]');
it('selects a date, emits ISO, updates display and returns focus without submitting a form', async () => {
    const wrapper = render(); await open(wrapper);
    expect(document.activeElement.dataset.date).toBe('2026-10-27');
    await wrapper.get('[data-date="2026-10-30"]').trigger('click');
    expect(wrapper.emitted('update:modelValue').at(-1)).toEqual(['2026-10-30']);
    expect(wrapper.get('#date').element.value).toBe('30/10/2026');
    expect(popup(wrapper).isVisible()).toBe(false);
    expect(document.activeElement.id).toBe('date');
    expect(wrapper.findAll('button').every(button => button.attributes('type') === 'button')).toBe(true);
});
it('keeps manual invalid values invalid and repairs validity after selection, model changes or clearing', async () => {
    const wrapper = render(); const input = wrapper.get('#date');
    await input.setValue('31/02/2026'); await input.trigger('blur');
    expect(input.element.checkValidity()).toBe(false); expect(input.attributes('aria-invalid')).toBe('true');
    await open(wrapper); await wrapper.get('[data-date="2026-10-29"]').trigger('click');
    expect(input.element.checkValidity()).toBe(true); expect(input.attributes('aria-invalid')).toBeUndefined();
    await input.setValue('29/02/2024'); expect(wrapper.emitted('update:modelValue').at(-1)).toEqual(['2024-02-29']);
    await wrapper.setProps({ modelValue: '' }); expect(input.element.value).toBe(''); expect(input.element.checkValidity()).toBe(true);
});
it('preserves required, disabled, readonly, inherited fieldset disabling and server error labels', async () => {
    const wrapper = render({ required: true, modelValue: '', errorKey: 'dateInvalid' });
    expect(wrapper.get('#date').element.checkValidity()).toBe(false);
    expect(wrapper.get('#date').attributes('aria-describedby')).toBe('date-error');
    expect(wrapper.get('label').attributes('for')).toBe('date');
    await open(wrapper); expect(popup(wrapper).isVisible()).toBe(true);
    await wrapper.setProps({ disabled: true }); await wrapper.get('#date').trigger('click'); expect(popup(wrapper).isVisible()).toBe(false);
    await wrapper.setProps({ disabled: false, readonly: true }); await wrapper.get('#date').trigger('click'); expect(popup(wrapper).isVisible()).toBe(false);
    await wrapper.setProps({ readonly: false });
    const fieldset = document.createElement('fieldset'); fieldset.disabled = true; document.body.appendChild(fieldset); fieldset.appendChild(wrapper.element);
    await wrapper.get('#date').trigger('click'); expect(popup(wrapper).isVisible()).toBe(false);
    wrapper.element.remove(); fieldset.remove();
});
it('navigates months, years and leap years without normalizing impossible days', async () => {
    const wrapper = render({ modelValue: '2024-01-31' }); await open(wrapper);
    await wrapper.get(`[aria-label="${en.calendarNext}"]`).trigger('click');
    expect(popup(wrapper).get('select').element.value).toBe('2');
    expect(wrapper.find('[data-date="2024-02-29"]').exists()).toBe(true);
    expect(wrapper.find('[data-date="2024-02-30"]').exists()).toBe(false);
    await wrapper.get(`[aria-label="${en.calendarYear}"]`).setValue('2026');
    expect(wrapper.find('[data-date="2026-02-29"]').exists()).toBe(false);
    await popup(wrapper).get('select').setValue('12');
    await wrapper.get(`[aria-label="${en.calendarNext}"]`).trigger('click');
    expect(wrapper.get(`[aria-label="${en.calendarYear}"]`).element.value).toBe('2027');
    expect(popup(wrapper).get('select').element.value).toBe('1');
});
it('supports arrow/week/month/year keyboard movement and Escape focus return', async () => {
    const wrapper = render({ modelValue: '2024-02-29' }); await wrapper.get('#date').trigger('keydown', { key: 'ArrowDown' }); await flushPromises();
    await wrapper.get('[data-date="2024-02-29"]').trigger('keydown', { key: 'ArrowRight' }); await flushPromises();
    expect(document.activeElement.dataset.date).toBe('2024-03-01');
    await wrapper.get('[data-date="2024-03-01"]').trigger('keydown', { key: 'Home' }); await flushPromises();
    expect(document.activeElement.dataset.date).toBe('2024-02-26');
    await wrapper.get('[data-date="2024-02-26"]').trigger('keydown', { key: 'PageDown', shiftKey: true }); await flushPromises();
    expect(document.activeElement.dataset.date).toBe('2025-02-26');
    expect(wrapper.findAll('table button[tabindex="0"]')).toHaveLength(1);
    document.activeElement.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true })); await nextTick();
    expect(popup(wrapper).isVisible()).toBe(false); expect(document.activeElement.id).toBe('date');
});
it.each(['en', 'lo'])('localizes Gregorian month, weekdays, navigation and validation in %s', async language => {
    const dictionary = language === 'lo' ? lo : en;
    const wrapper = render({}, language); await open(wrapper);
    expect(wrapper.find(`[aria-label="${dictionary.calendarNext}"]`).exists()).toBe(true);
    expect(popup(wrapper).get('select').text()).toContain(language === 'lo' ? 'ຕຸລາ' : 'October');
    expect(wrapper.get('th').attributes('aria-label')).toBe(language === 'lo' ? 'ວັນຈັນ' : 'Monday');
    await wrapper.get('#date').setValue('29/02/2026'); await wrapper.get('#date').trigger('blur');
    expect(wrapper.get('#date').element.validationMessage).toBe(dictionary.dateInvalid);
    expect(wrapper.get('[role="alert"]').text()).toBe(dictionary.dateInvalid);
});
it.each(['America/Los_Angeles', 'Pacific/Kiritimati'])('keeps calendar and selection date-only in %s', async timezone => {
    vi.stubEnv('TZ', timezone); vi.useFakeTimers(); vi.setSystemTime(new Date('2026-10-27T18:00:00Z'));
    expect(laosToday()).toBe('2026-10-28');
    expect(moveCalendarDay('2024-02-28', 1)).toBe('2024-02-29');
    expect(moveCalendarMonth('2024-01-31', 1)).toBe('2024-02-29');
    expect(moveCalendarMonth('2024-02-29', 12)).toBe('2025-02-28');
    expect(calendarDate(1900, 2, 29)).toBeNull(); expect(calendarDate(2000, 2, 29)).toBe('2000-02-29');
    const wrapper = render({ modelValue: '0001-01-01' });
    await wrapper.get('button[aria-haspopup="dialog"]').trigger('click'); await nextTick(); await nextTick();
    expect(wrapper.find('[data-date="0001-01-01"]').exists()).toBe(true);
    await wrapper.get('[data-date="0001-01-02"]').trigger('click');
    expect(wrapper.emitted('update:modelValue').at(-1)).toEqual(['0001-01-02']);
    expect(wrapper.get('#date').element.value).toBe('02/01/0001');
});
it('closes on outside pointer or Tab focus, and clears optional dates', async () => {
    const wrapper = render(); await open(wrapper);
    document.body.dispatchEvent(new Event('pointerdown', { bubbles: true })); await nextTick(); expect(popup(wrapper).isVisible()).toBe(false);
    await open(wrapper); const outside = document.createElement('button'); document.body.appendChild(outside); outside.focus(); await nextTick(); expect(popup(wrapper).isVisible()).toBe(false); outside.remove();
    await open(wrapper); await wrapper.findAll('[role="dialog"] button').find(button => button.text() === en.calendarClear).trigger('click');
    expect(wrapper.emitted('update:modelValue').at(-1)).toEqual(['']); expect(wrapper.get('#date').element.value).toBe('');
});
it('honors ISO min/max bounds for selection and manual entry', async () => {
    const wrapper = render({ min: '2026-10-20', max: '2026-10-30' }); await open(wrapper);
    expect(wrapper.get('[data-date="2026-10-19"]').attributes('disabled')).toBeDefined();
    await wrapper.get('[data-date="2026-10-27"]').trigger('keydown', { key: 'ArrowDown' }); await flushPromises();
    expect(document.activeElement.dataset.date).toBe('2026-10-30');
    await wrapper.get('#date').setValue('31/10/2026'); await wrapper.get('#date').trigger('blur');
    expect(wrapper.get('#date').element.checkValidity()).toBe(false);
});
