<script setup>
import { Icon } from '@iconify/vue';

// Intro banner for a create/edit form. The <h2> stays first so callers can focus it after opening the form.
defineProps({
    eyebrow: { type: String, default: '' },
    title: { type: String, required: true },
    description: { type: String, default: '' },
    icon: { type: Object, default: null },
});
</script>

<template>
    <header class="relative overflow-hidden rounded-2xl border border-teal-100 bg-linear-to-br from-teal-50 via-white to-cyan-50 p-5 sm:p-6">
        <div class="pointer-events-none absolute -top-16 -right-16 size-48 rounded-full bg-teal-200/30 blur-3xl" aria-hidden="true"></div>
        <div class="relative flex items-start gap-4">
            <span v-if="icon" class="grid size-12 shrink-0 place-items-center rounded-2xl bg-linear-to-br from-teal-600 to-cyan-600 text-white shadow-lg shadow-teal-700/20" aria-hidden="true"><Icon :icon="icon" class="size-6" /></span>
            <div class="flex min-w-0 flex-col gap-1">
                <p v-if="eyebrow" class="text-xs font-semibold tracking-wider text-teal-700 uppercase">{{ eyebrow }}</p>
                <h2 tabindex="-1" class="text-xl font-semibold text-slate-900">{{ title }}</h2>
                <p v-if="description" class="max-w-3xl text-sm leading-6 text-slate-600">{{ description }}</p>
                <slot />
            </div>
        </div>
    </header>
</template>
