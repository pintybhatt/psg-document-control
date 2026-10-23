<script setup>
import AppIcon from '../components/AppIcon.vue';
import { useLocalization } from '../localization/index.js';

defineProps({
    page: { type: String, required: true },
    dashboardHref: { type: String, required: true },
});

const { t } = useLocalization();
</script>

<template>
    <section class="flex min-h-80 flex-col items-center justify-center gap-5 rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center">
        <span class="rounded-2xl bg-teal-50 p-4 text-teal-700"><AppIcon :name="page" class="size-8" /></span>
        <h2 class="text-xl font-semibold">{{ t('placeholderTitle') }}</h2>
        <p class="max-w-lg text-sm leading-7 text-slate-500">{{ t('placeholderDescription') }}</p>
        <a :href="dashboardHref" class="rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-slate-700">{{ t('backToDashboard') }}</a>
    </section>
</template>
