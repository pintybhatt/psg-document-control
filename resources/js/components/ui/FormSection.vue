<script setup>
import { Icon } from '@iconify/vue';
import chevronDown from '@iconify-icons/flowbite/chevron-down-outline';
import { computed, useId } from 'vue';

// A titled card that groups related form fields. `collapsible` renders it as a <details> disclosure.
const props = defineProps({
    title: { type: String, required: true },
    description: { type: String, default: '' },
    icon: { type: Object, default: null },
    collapsible: Boolean,
    open: Boolean,
    disabled: Boolean,
    columns: { type: Number, default: 2, validator: value => [1, 2, 3].includes(value) },
});
const titleId = `form-section-${useId()}`;
const gridClass = computed(() => ({ 1: 'grid gap-x-5 gap-y-4', 2: 'grid gap-x-5 gap-y-4 md:grid-cols-2', 3: 'grid gap-x-5 gap-y-4 md:grid-cols-3' })[props.columns]);
</script>

<template>
    <component :is="collapsible ? 'details' : 'section'" :open="collapsible ? open : undefined" :aria-labelledby="collapsible ? undefined : titleId" class="group/section rounded-2xl border border-slate-200 bg-white">
        <component :is="collapsible ? 'summary' : 'header'" class="flex items-start gap-3 px-4 py-4 sm:px-5" :class="collapsible ? 'cursor-pointer list-none [&::-webkit-details-marker]:hidden' : 'border-b border-slate-100'">
            <span v-if="icon" class="grid size-9 shrink-0 place-items-center rounded-xl bg-teal-50 text-teal-700 ring-1 ring-teal-100" aria-hidden="true"><Icon :icon="icon" class="size-5" /></span>
            <div class="flex min-w-0 grow flex-col gap-0.5">
                <h3 :id="titleId" class="font-semibold text-slate-900">{{ title }}</h3>
                <p v-if="description" class="text-sm text-slate-500">{{ description }}</p>
            </div>
            <slot name="actions" />
            <Icon v-if="collapsible" :icon="chevronDown" aria-hidden="true" class="mt-2 size-4 shrink-0 text-slate-400 transition-transform group-open/section:rotate-180 motion-reduce:transition-none" />
        </component>
        <fieldset :disabled="disabled" :aria-labelledby="titleId" class="min-w-0 px-4 pt-1 pb-5 sm:px-5" :class="collapsible ? 'border-t border-slate-100 pt-4' : 'pt-4'">
            <div :class="gridClass"><slot /></div>
        </fieldset>
    </component>
</template>
