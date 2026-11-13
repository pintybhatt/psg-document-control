<script setup>
import { computed, onBeforeUnmount, onMounted, onUpdated, ref, useAttrs, watch } from 'vue';
import Datepicker from 'vanillajs-datepicker/Datepicker';
import 'vanillajs-datepicker/css/datepicker-bs5.css';
import { formatDate, parseDisplayDate, toIsoDate } from '../../utilities/dates.js';
import { laosToday } from '../../utilities/dateCalendar.js';
import { useLocalization } from '../../localization/index.js';
import en from '../../localization/en.js';
import lo from '../../localization/lo.js';
import AppIcon from '../AppIcon.vue';
import FormField from './FormField.vue';
import { formFieldProps } from './formFieldProps.js';

// Bootstrap 5 styled datepicker (vanillajs-datepicker). The model stays an ISO date string (yyyy-mm-dd);
// the field shows dd/mm/yyyy and still accepts typed dates. Days after today (Laos time) are disabled
// unless the field opts in with `allow-future` (for due, review, delivery and similar dates).
defineOptions({ inheritAttrs: false });
const props = defineProps({ ...formFieldProps, allowFuture: Boolean });
const model = defineModel({ type: [String, Number], default: '' });
const attrs = useAttrs();
const { t, language } = useLocalization();
const input = ref(null);
const displayed = ref(''), invalid = ref(false);
let picker = null, toggleWasOpen = false;

// Build the picker's locales from the app's own translations (Monday-first weeks, Gregorian calendar).
function localeFrom(dictionary) {
    const months = Array.from({ length: 12 }, (_, index) => dictionary[`calendarMonth-${index + 1}`]);
    const sundayFirst = list => [list[6], ...list.slice(0, 6)];
    const days = sundayFirst(Array.from({ length: 7 }, (_, index) => dictionary[`calendarWeekday-${index}`]));
    const daysMin = sundayFirst(Array.from({ length: 7 }, (_, index) => dictionary[`calendarWeekdayShort-${index}`]));
    return { days, daysShort: daysMin, daysMin, months, monthsShort: months, today: dictionary.calendarToday, clear: dictionary.calendarClear, titleFormat: 'MM yyyy', weekStart: 1 };
}
Object.assign(Datepicker.locales, { 'dcms-en': localeFrom(en), 'dcms-lo': localeFrom(lo) });

function toDate(iso) {
    const [year, month, day] = iso.split('-').map(Number);
    const date = new Date(2000, 0, 1);
    date.setFullYear(year, month - 1, day);
    return date;
}
function toIso(date) {
    return `${String(date.getFullYear()).padStart(4, '0')}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

const minDate = computed(() => toIsoDate(attrs.min) ?? null);
const maxDate = computed(() => {
    const explicit = toIsoDate(attrs.max);
    if (props.allowFuture) { return explicit ?? null; }
    const today = laosToday();
    return explicit && explicit < today ? explicit : today;
});
const selectable = value => !!value && (!minDate.value || value >= minDate.value) && (!maxDate.value || value <= maxDate.value);
const unavailable = () => !input.value || input.value.matches(':disabled') || input.value.readOnly;
const isOpen = () => !!picker?.active;

function syncValidity() {
    const parsed = parseDisplayDate(displayed.value);
    input.value?.setCustomValidity(displayed.value && (!parsed || !selectable(parsed)) ? t('dateInvalid') : '');
}
function syncPicker() {
    if (!picker) { return; }
    const iso = toIsoDate(String(model.value ?? ''));
    const current = picker.dates.length ? toIso(new Date(picker.dates[0])) : '';
    if (iso === current || (!iso && !current)) { return; }
    if (iso) { picker.setDate(toDate(iso)); } else { picker.setDate({ clear: true }); }
    if (input.value) { input.value.value = displayed.value; }
}

watch(model, value => {
    if (!value || toIsoDate(String(value))) {
        displayed.value = value ? formatDate(String(value), '') : ''; invalid.value = false;
        syncPicker();
    }
}, { immediate: true });
watch([displayed, language], syncValidity, { flush: 'post' });
watch(language, value => picker?.setOptions({ language: `dcms-${value}` }));
watch([minDate, maxDate], ([min, max]) => picker?.setOptions({ minDate: min ? toDate(min) : null, maxDate: max ? toDate(max) : null }));

function update(event) {
    displayed.value = event.target.value;
    const parsed = parseDisplayDate(displayed.value);
    if (!displayed.value || (parsed && selectable(parsed))) { invalid.value = false; }
    model.value = parsed ?? (displayed.value || '');
    syncValidity();
}
function validate() { invalid.value = !!displayed.value && (!parseDisplayDate(displayed.value) || !selectable(parseDisplayDate(displayed.value))); }
function picked(event) {
    const date = event.detail?.date;
    const iso = date ? toIso(date) : '';
    if (iso && !selectable(iso)) { return; }
    displayed.value = iso ? formatDate(iso, '') : ''; invalid.value = false;
    if (model.value !== iso) { model.value = iso; }
    if (input.value) { input.value.value = displayed.value; }
    syncValidity();
}
function show() { if (!unavailable()) { picker?.show(); } }
function hide() { picker?.hide(); }
function rememberToggle() { toggleWasOpen = isOpen(); }
function toggle() {
    if (toggleWasOpen) { toggleWasOpen = false; hide(); input.value?.focus({ preventScroll: true }); return; }
    show(); input.value?.focus({ preventScroll: true });
}
function inputKey(event) { if (event.key === 'ArrowDown' && !isOpen()) { event.preventDefault(); show(); } }
function reposition() { if (isOpen()) { picker.picker.place(); } }

onMounted(() => {
    picker = new Datepicker(input.value, {
        format: 'dd/mm/yyyy', language: `dcms-${language.value}`, weekStart: 1,
        minDate: minDate.value ? toDate(minDate.value) : null, maxDate: maxDate.value ? toDate(maxDate.value) : null,
        autohide: true, todayButton: true, todayButtonMode: 1, todayHighlight: true, clearButton: !props.required,
        showOnClick: false, showOnFocus: false, updateOnBlur: false,
        container: document.body, buttonClass: 'btn', prevArrow: '‹', nextArrow: '›',
    });
    picker.picker.element.classList.add('dcms-datepicker');
    if (toIsoDate(String(model.value ?? ''))) { picker.setDate(toDate(String(model.value))); }
    input.value.value = displayed.value;
    input.value.addEventListener('changeDate', picked);
    window.addEventListener('scroll', reposition, true);
    syncValidity();
});
onUpdated(() => { syncValidity(); if (isOpen() && unavailable()) { hide(); } });
onBeforeUnmount(() => {
    window.removeEventListener('scroll', reposition, true);
    input.value?.removeEventListener('changeDate', picked);
    picker?.destroy(); picker = null;
});
</script>

<template>
    <FormField :id="id" :label="label" :helper="helper" :error="error" :error-key="errorKey || (invalid ? 'dateInvalid' : '')" :required="required" v-slot="{ controlAttrs }">
        <div class="relative min-w-0">
            <input ref="input" :value="displayed" v-bind="{ ...$attrs, ...controlAttrs }" type="text" inputmode="numeric" autocomplete="off" placeholder="dd/mm/yyyy" pattern="[0-9]{2}/[0-9]{2}/[0-9]{4}" class="ui-control pr-11 tabular-nums" aria-haspopup="dialog" @input="update" @blur="validate" @click="show" @keydown="inputKey" />
            <button type="button" :title="t('calendarOpen', { field: label })" :disabled="!!input?.disabled || !!input?.readOnly || ($attrs.disabled !== undefined && $attrs.disabled !== false) || ($attrs.readonly !== undefined && $attrs.readonly !== false)" class="absolute inset-y-0 right-0 flex w-10 items-center justify-center rounded-r-lg text-slate-500 hover:text-teal-800 focus-visible:outline-2 focus-visible:outline-teal-600 disabled:cursor-not-allowed disabled:text-slate-300" :aria-label="t('calendarOpen', { field: label })" aria-haspopup="dialog" data-calendar-toggle @mousedown="rememberToggle" @click="toggle"><AppIcon name="calendar" class="size-4" /></button>
        </div>
    </FormField>
</template>
