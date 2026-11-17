<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, onUpdated, ref, useAttrs, useId, watch } from 'vue';
import { formatDate, parseDisplayDate, toIsoDate } from '../../utilities/dates.js';
import { calendarDate, laosToday, moveCalendarDay, moveCalendarMonth, utcCalendarDate } from '../../utilities/dateCalendar.js';
import { useLocalization } from '../../localization/index.js';
import AppIcon from '../AppIcon.vue';
import FormField from './FormField.vue';
import { formFieldProps } from './formFieldProps.js';

defineOptions({ inheritAttrs: false });
defineProps(formFieldProps);
const model = defineModel({ type: [String, Number], default: '' });
const attrs = useAttrs();
const { t, language } = useLocalization();
const popupId = `calendar-${useId()}`;
const field = ref(null), input = ref(null), popup = ref(null);
const displayed = ref(''), invalid = ref(false), open = ref(false), focusedDate = ref(laosToday()), today = ref(laosToday());
const position = ref({ left: '0px', top: '0px', width: '320px' });
const nativePopover = typeof HTMLElement !== 'undefined' && 'showPopover' in HTMLElement.prototype;
const selected = computed(() => toIsoDate(String(model.value)));
const year = computed(() => Number(focusedDate.value.slice(0, 4)));
const month = computed(() => Number(focusedDate.value.slice(5, 7)));
const months = computed(() => Array.from({ length: 12 }, (_, index) => t(`calendarMonth-${index + 1}`)));
const weekdays = computed(() => Array.from({ length: 7 }, (_, index) => ({ short: t(`calendarWeekdayShort-${index}`), full: t(`calendarWeekday-${index}`) })));
const monthLabel = computed(() => `${months.value[month.value - 1]} ${year.value}`);
const days = computed(() => {
    const first = calendarDate(year.value, month.value);
    const offset = (utcCalendarDate(first).getUTCDay() + 6) % 7;
    const firstDate = utcCalendarDate(first);
    firstDate.setUTCDate(firstDate.getUTCDate() - offset);
    return Array.from({ length: 6 }, (_, week) => Array.from({ length: 7 }, (_, day) => {
        const date = new Date(firstDate);
        date.setUTCDate(date.getUTCDate() + week * 7 + day);
        return calendarDate(date.getUTCFullYear(), date.getUTCMonth() + 1, date.getUTCDate());
    }));
});
const unavailable = () => !input.value || input.value.matches(':disabled') || input.value.readOnly;
const selectable = value => value && (!toIsoDate(attrs.min) || value >= attrs.min) && (!toIsoDate(attrs.max) || value <= attrs.max);
const dayLabel = value => t('calendarDateLabel', { weekday: weekdays.value[(utcCalendarDate(value).getUTCDay() + 6) % 7].full, day: Number(value.slice(8)), month: months.value[Number(value.slice(5, 7)) - 1], year: value.slice(0, 4) });
function boundedDate(value) {
    if (toIsoDate(attrs.min) && value < attrs.min) { return attrs.min; }
    if (toIsoDate(attrs.max) && value > attrs.max) { return attrs.max; }
    return value;
}
function syncValidity() {
    const parsed = parseDisplayDate(displayed.value);
    input.value?.setCustomValidity(displayed.value && (!parsed || !selectable(parsed)) ? t('dateInvalid') : '');
}
watch(model, value => {
    if (!value || toIsoDate(String(value))) {
        displayed.value = value ? formatDate(String(value), '') : ''; invalid.value = false;
        if (value) { focusedDate.value = String(value); }
    }
}, { immediate: true });
watch([displayed, language], syncValidity, { flush: 'post' });
function update(event) {
    displayed.value = event.target.value;
    const parsed = parseDisplayDate(displayed.value);
    if (!displayed.value || (parsed && selectable(parsed))) { invalid.value = false; }
    model.value = parsed ?? (displayed.value || '');
    syncValidity();
}
function validate() { invalid.value = !!displayed.value && (!parseDisplayDate(displayed.value) || !selectable(parseDisplayDate(displayed.value))); }
async function focusDay() {
    await nextTick();
    popup.value?.querySelector(`[data-date="${focusedDate.value}"]`)?.focus({ preventScroll: true });
}
function place() {
    if (!open.value || !field.value || !popup.value) { return; }
    const rect = field.value.getBoundingClientRect();
    const viewport = window.visualViewport;
    const left = viewport?.offsetLeft ?? 0, top = viewport?.offsetTop ?? 0;
    const width = viewport?.width ?? window.innerWidth, height = viewport?.height ?? window.innerHeight;
    const popupWidth = Math.min(320, width - 16);
    const below = Math.max(0, top + height - rect.bottom - 12), above = Math.max(0, rect.top - top - 12);
    const naturalHeight = popup.value.scrollHeight + 2;
    const useAbove = below < naturalHeight && above > below;
    const available = Math.max(0, useAbove ? above : below);
    const popupHeight = Math.min(naturalHeight, available);
    position.value = {
        width: `${popupWidth}px`, maxHeight: `${available}px`,
        left: `${Math.max(left + 8, Math.min(rect.left, left + width - popupWidth - 8))}px`,
        top: `${useAbove ? Math.max(top + 8, rect.top - popupHeight - 6) : Math.max(top + 8, rect.bottom + 6)}px`,
    };
}
async function show(focus = true) {
    if (unavailable()) { return; }
    if (!open.value) {
        today.value = laosToday(); focusedDate.value = boundedDate(selected.value ?? today.value);
        open.value = true;
        await nextTick();
        if (!open.value) { return; }
        if (nativePopover) { popup.value.showPopover(); }
        place();
    }
    if (focus) { await focusDay(); }
}
function close(restoreFocus = false) {
    if (!open.value) { return; }
    open.value = false;
    if (nativePopover && popup.value?.matches(':popover-open')) { popup.value.hidePopover(); }
    if (restoreFocus) { input.value?.focus({ preventScroll: true }); }
}
function choose(value) {
    if (unavailable() || !selectable(value)) { return; }
    model.value = value; displayed.value = formatDate(value, ''); invalid.value = false;
    syncValidity(); close(true);
}
function clear() { if (!unavailable()) { model.value = ''; displayed.value = ''; invalid.value = false; syncValidity(); close(true); } }
async function navigate(months) { focusedDate.value = boundedDate(moveCalendarMonth(focusedDate.value, months)); await nextTick(); place(); }
async function changeMonth(event) { await navigate(Number(event.target.value) - month.value); event.target.value = month.value; }
async function changeYear(event) {
    const value = Number(event.target.value);
    if (Number.isInteger(value) && value >= 1 && value <= 9999) { await navigate((value - year.value) * 12); event.target.value = year.value; }
    else { event.target.value = year.value; }
}
async function dayKey(event) {
    const value = event.target.dataset.date;
    const offset = (utcCalendarDate(value).getUTCDay() + 6) % 7;
    let next;
    switch (event.key) {
        case 'ArrowLeft': next = moveCalendarDay(value, -1); break;
        case 'ArrowRight': next = moveCalendarDay(value, 1); break;
        case 'ArrowUp': next = moveCalendarDay(value, -7); break;
        case 'ArrowDown': next = moveCalendarDay(value, 7); break;
        case 'Home': next = moveCalendarDay(value, -offset); break;
        case 'End': next = moveCalendarDay(value, 6 - offset); break;
        case 'PageUp': next = moveCalendarMonth(value, event.shiftKey ? -12 : -1); break;
        case 'PageDown': next = moveCalendarMonth(value, event.shiftKey ? 12 : 1); break;
        default: return;
    }
    event.preventDefault(); focusedDate.value = boundedDate(next); await focusDay(); place();
}
function inputKey(event) { if (event.key === 'ArrowDown') { event.preventDefault(); show(); } }
watch(open, (value, previous, onCleanup) => {
    if (!value) { return; }
    const inside = target => field.value?.contains(target) || popup.value?.contains(target);
    const outside = event => { if (!inside(event.target)) { close(); } };
    const escape = event => { if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close(true); } };
    document.addEventListener('pointerdown', outside, true);
    document.addEventListener('focusin', outside);
    document.addEventListener('keydown', escape, true);
    window.addEventListener('resize', place);
    window.addEventListener('scroll', place, true);
    window.visualViewport?.addEventListener('resize', place);
    window.visualViewport?.addEventListener('scroll', place);
    onCleanup(() => {
        document.removeEventListener('pointerdown', outside, true); document.removeEventListener('focusin', outside); document.removeEventListener('keydown', escape, true);
        window.removeEventListener('resize', place); window.removeEventListener('scroll', place, true);
        window.visualViewport?.removeEventListener('resize', place); window.visualViewport?.removeEventListener('scroll', place);
    });
});
onMounted(syncValidity);
onUpdated(() => { syncValidity(); if (open.value && unavailable()) { close(); } });
onBeforeUnmount(() => close());
</script>

<template>
    <FormField :id="id" :label="label" :helper="helper" :error="error" :error-key="errorKey || (invalid ? 'dateInvalid' : '')" :required="required" v-slot="{ controlAttrs }">
        <div ref="field" class="relative min-w-0">
            <input ref="input" :value="displayed" v-bind="{ ...$attrs, ...controlAttrs }" type="text" inputmode="numeric" autocomplete="off" placeholder="dd/mm/yyyy" pattern="[0-9]{2}/[0-9]{2}/[0-9]{4}" class="ui-control pr-11 tabular-nums" :aria-controls="open ? popupId : undefined" aria-haspopup="dialog" :aria-expanded="open" @input="update" @blur="validate" @click="show(false)" @keydown="inputKey" />
            <button type="button" :disabled="!!input?.disabled || !!input?.readOnly || ($attrs.disabled !== undefined && $attrs.disabled !== false) || ($attrs.readonly !== undefined && $attrs.readonly !== false)" class="absolute inset-y-0 right-0 flex w-10 items-center justify-center rounded-r-lg text-slate-500 hover:text-teal-800 focus-visible:outline-2 focus-visible:outline-teal-600 disabled:cursor-not-allowed disabled:text-slate-300" :aria-label="t('calendarOpen', { field: label })" aria-haspopup="dialog" :aria-expanded="open" :aria-controls="popupId" @click="open ? close(true) : show()"><AppIcon name="calendar" class="size-4" /></button>
        </div>
        <Teleport to="body" :disabled="nativePopover">
        <div :id="popupId" ref="popup" v-show="open" :popover="nativePopover ? 'auto' : undefined" role="dialog" :aria-label="t('calendarTitle', { field: label })" :aria-describedby="`${popupId}-help`" class="dcms-date-calendar fixed z-[1080] m-0 overflow-y-auto rounded-xl border border-slate-300 bg-white p-3 font-sans text-sm text-slate-800 shadow-lg" :style="position" @toggle="event => { if (event.newState === 'closed') { open = false; } }">
            <p :id="`${popupId}-help`" class="sr-only">{{ t('calendarHelp') }}</p>
            <p class="sr-only" role="status" aria-live="polite" aria-atomic="true">{{ monthLabel }}</p>
            <div class="mb-3 flex items-center gap-1">
                <button type="button" class="size-8 shrink-0 rounded-md text-xl text-slate-600 hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-teal-600 disabled:opacity-30" :disabled="year === 1 && month === 1" :aria-label="t('calendarPrevious')" @click="navigate(-1)">‹</button>
                <select :value="month" :aria-label="t('calendarMonth')" class="h-8 min-w-0 flex-1 rounded-md border border-slate-300 bg-white px-1 text-sm font-semibold text-slate-800 focus-visible:outline-2 focus-visible:outline-teal-600" @change="changeMonth"><option v-for="(name, index) in months" :key="index" :value="index + 1">{{ name }}</option></select>
                <input :value="year" type="number" min="1" max="9999" inputmode="numeric" :aria-label="t('calendarYear')" class="h-8 w-18 shrink-0 rounded-md border border-slate-300 px-1 text-sm tabular-nums focus-visible:outline-2 focus-visible:outline-teal-600" @change="changeYear" @keydown.enter.prevent="changeYear" />
                <button type="button" class="size-8 shrink-0 rounded-md text-xl text-slate-600 hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-teal-600 disabled:opacity-30" :disabled="year === 9999 && month === 12" :aria-label="t('calendarNext')" @click="navigate(1)">›</button>
            </div>
            <table role="grid" :aria-label="monthLabel" class="w-full table-fixed border-separate border-spacing-0 text-center">
                <thead><tr><th v-for="(day, index) in weekdays" :key="index" scope="col" :aria-label="day.full" class="h-8 text-xs font-medium text-slate-500">{{ day.short }}</th></tr></thead>
                <tbody><tr v-for="(week, index) in days" :key="index"><td v-for="(day, column) in week" :key="column" :aria-selected="day === selected" class="p-0.5"><button v-if="day" type="button" :data-date="day" :disabled="!selectable(day)" :tabindex="day === focusedDate ? 0 : -1" :aria-label="dayLabel(day)" :aria-current="day === today ? 'date' : undefined" class="h-9 w-full rounded-md text-sm tabular-nums focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-teal-600 disabled:opacity-30" :class="day === selected ? 'bg-teal-700 font-semibold text-white hover:bg-teal-800' : [day.slice(5, 7) === focusedDate.slice(5, 7) ? 'text-slate-800 hover:bg-slate-100' : 'text-slate-400 hover:bg-slate-100', day === today ? 'ring-1 ring-inset ring-teal-600' : '']" @click="choose(day)" @keydown="dayKey">{{ Number(day.slice(8)) }}</button></td></tr></tbody>
            </table>
            <div class="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-2">
                <button type="button" class="rounded px-1 py-1 text-xs font-semibold text-teal-800 hover:bg-teal-50 focus-visible:outline-2 focus-visible:outline-teal-600 disabled:opacity-30" :disabled="!selectable(today)" @click="choose(today)">{{ t('calendarToday') }}</button>
                <button v-if="!required" type="button" class="rounded px-1 py-1 text-xs text-slate-600 hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-teal-600" @click="clear">{{ t('calendarClear') }}</button>
                <button type="button" class="rounded px-1 py-1 text-xs text-slate-600 hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-teal-600" @click="close(true)">{{ t('calendarClose') }}</button>
            </div>
        </div>
        </Teleport>
    </FormField>
</template>
