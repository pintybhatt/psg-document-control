<script setup>
import { useLocalization } from '../../localization/index.js';

// Sticky footer for a long form: required-field note on the left, actions on the right.
const { t } = useLocalization();
</script>

<template>
    <div class="sticky bottom-3 z-10 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-3 shadow-lg shadow-slate-900/5 sm:px-5">
        <p class="text-xs text-slate-500"><span class="text-red-700" aria-hidden="true">*</span> {{ t('formRequiredNote') }}</p>
        <div class="flex flex-wrap items-center gap-3"><slot /></div>
    </div>
</template>
