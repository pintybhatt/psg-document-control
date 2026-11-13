import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import TextInput from '../../resources/js/components/ui/TextInput.vue';
import { createLocalization } from '../../resources/js/localization/index.js';
import { calendarDate, laosToday, moveCalendarDay, moveCalendarMonth } from '../../resources/js/utilities/dateCalendar.js';
import en from '../../resources/js/localization/en.js';
import lo from '../../resources/js/localization/lo.js';

enableAutoUnmount(afterEach);
// "Today" in Laos is 28/10/2026 for every test.
beforeEach(() => { vi.useFakeTimers({ toFake: ['Date'] }); vi.setSystemTime(new Date('2026-10-28T03:00:00Z')); });
afterEach(() => { vi.useRealTimers(); vi.unstubAllEnvs(); document.querySelectorAll('.datepicker').forEach(element => element.remove()); });

function render(props = {}, language = 'en') {
    return mount(TextInput, { props: { id: 'date', type: 'date', label: 'Document date', modelValue: '2026-10-20', ...props }, attachTo: document.body, global: { plugins: [createLocalization(language)] } });
}
const picker = () => document.querySelector('.datepicker.dcms-datepicker');
const isOpen = () => picker().classList.contains('active');
const iso = date => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
const cell = value => [...picker().querySelectorAll('.datepicker-cell.day')].find(element => iso(new Date(Number(element.dataset.date))) === value);
async function open(wrapper) {
    const toggle = wrapper.get('[data-calendar-toggle]');
    await toggle.trigger('mousedown'); await toggle.trigger('click'); await flushPromises();
}
async function pick(value) { cell(value).dispatchEvent(new MouseEvent('click', { bubbles: true })); await flushPromises(); }

it('uses a solid Bootstrap 5 datepicker attached to the page body', async () => {
    const wrapper = render(); await open(wrapper);
    expect(picker().parentElement).toBe(document.body);
    expect(isOpen()).toBe(true);
    expect(wrapper.findAll('button').every(button => button.attributes('type') === 'button')).toBe(true);
    expect(wrapper.get('[data-calendar-toggle]').attributes('title')).toBe('Open calendar for Document date');
});

it('selects a date, emits ISO, shows dd/mm/yyyy and closes', async () => {
    const wrapper = render(); await open(wrapper);
    await pick('2026-10-25');
    expect(wrapper.emitted('update:modelValue').at(-1)).toEqual(['2026-10-25']);
    expect(wrapper.get('#date').element.value).toBe('25/10/2026');
    expect(isOpen()).toBe(false);
});

it('disables days after today by default', async () => {
    const wrapper = render(); await open(wrapper);
    expect(cell('2026-10-28').classList.contains('disabled')).toBe(false);
    expect(cell('2026-10-29').classList.contains('disabled')).toBe(true);
    await pick('2026-10-29');
    expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    await wrapper.get('#date').setValue('29/10/2026'); await wrapper.get('#date').trigger('blur');
    expect(wrapper.get('#date').element.checkValidity()).toBe(false);
    expect(wrapper.get('[role="alert"]').text()).toBe(en.dateInvalid);
});

it('allows future days when the field opts in with allow-future', async () => {
    const wrapper = render({ allowFuture: true }); await open(wrapper);
    expect(cell('2026-10-29').classList.contains('disabled')).toBe(false);
    await pick('2026-10-30');
    expect(wrapper.emitted('update:modelValue').at(-1)).toEqual(['2026-10-30']);
    await wrapper.get('#date').setValue('15/12/2026');
    expect(wrapper.get('#date').element.checkValidity()).toBe(true);
});

it('keeps manual invalid values invalid and repairs validity after selection, model changes or clearing', async () => {
    const wrapper = render(); const input = wrapper.get('#date');
    await input.setValue('31/02/2026'); await input.trigger('blur');
    expect(input.element.checkValidity()).toBe(false); expect(input.attributes('aria-invalid')).toBe('true');
    await open(wrapper); await pick('2026-10-21');
    expect(input.element.checkValidity()).toBe(true); expect(input.attributes('aria-invalid')).toBeUndefined();
    await input.setValue('29/02/2024'); expect(wrapper.emitted('update:modelValue').at(-1)).toEqual(['2024-02-29']);
    await wrapper.setProps({ modelValue: '' }); expect(input.element.value).toBe(''); expect(input.element.checkValidity()).toBe(true);
});

it('does not open when required-empty fields are disabled, readonly or inside a disabled fieldset', async () => {
    const wrapper = render({ required: true, modelValue: '', errorKey: 'dateInvalid' });
    expect(wrapper.get('#date').element.checkValidity()).toBe(false);
    expect(wrapper.get('#date').attributes('aria-describedby')).toBe('date-error');
    expect(wrapper.get('label').attributes('for')).toBe('date');
    expect(picker().querySelector('.clear-button').style.display).toBe('none');
    await wrapper.setProps({ disabled: true }); await wrapper.get('#date').trigger('click'); expect(isOpen()).toBe(false);
    await wrapper.setProps({ disabled: false, readonly: true }); await wrapper.get('#date').trigger('click'); expect(isOpen()).toBe(false);
    await wrapper.setProps({ readonly: false });
    const fieldset = document.createElement('fieldset'); fieldset.disabled = true; document.body.appendChild(fieldset); fieldset.appendChild(wrapper.element);
    await wrapper.get('#date').trigger('click'); expect(isOpen()).toBe(false);
    wrapper.element.remove(); fieldset.remove();
});

it('clears optional dates from the picker', async () => {
    const wrapper = render(); await open(wrapper);
    picker().querySelector('.clear-button').dispatchEvent(new MouseEvent('click', { bubbles: true })); await flushPromises();
    expect(wrapper.emitted('update:modelValue').at(-1)).toEqual(['']); expect(wrapper.get('#date').element.value).toBe('');
});

it.each(['en', 'lo'])('localizes months, weekdays and validation in %s', async language => {
    const dictionary = language === 'lo' ? lo : en;
    const wrapper = render({}, language); await open(wrapper);
    expect(picker().querySelector('.view-switch').textContent).toBe(`${dictionary['calendarMonth-10']} 2026`);
    expect(picker().querySelector('.dow').textContent).toBe(dictionary['calendarWeekdayShort-0']);
    await wrapper.get('#date').setValue('29/02/2026'); await wrapper.get('#date').trigger('blur');
    expect(wrapper.get('#date').element.validationMessage).toBe(dictionary.dateInvalid);
    expect(wrapper.get('[role="alert"]').text()).toBe(dictionary.dateInvalid);
});

it('honors ISO min bounds for selection and manual entry', async () => {
    const wrapper = render({ min: '2026-10-20', modelValue: '2026-10-22' }); await open(wrapper);
    expect(cell('2026-10-19').classList.contains('disabled')).toBe(true);
    await wrapper.get('#date').setValue('19/10/2026'); await wrapper.get('#date').trigger('blur');
    expect(wrapper.get('#date').element.checkValidity()).toBe(false);
});

it.each(['America/Los_Angeles', 'Pacific/Kiritimati'])('keeps calendar helpers date-only in %s', timezone => {
    vi.stubEnv('TZ', timezone); vi.setSystemTime(new Date('2026-10-27T18:00:00Z'));
    expect(laosToday()).toBe('2026-10-28');
    expect(moveCalendarDay('2024-02-28', 1)).toBe('2024-02-29');
    expect(moveCalendarMonth('2024-01-31', 1)).toBe('2024-02-29');
    expect(moveCalendarMonth('2024-02-29', 12)).toBe('2025-02-28');
    expect(calendarDate(1900, 2, 29)).toBeNull(); expect(calendarDate(2000, 2, 29)).toBe('2000-02-29');
});
