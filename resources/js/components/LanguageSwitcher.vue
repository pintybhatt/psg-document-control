<script setup>
import { useLocalization } from '../localization/index.js';

const { language, setLanguage, t } = useLocalization();
</script>

<template>
    <label class="flex items-center gap-2 text-sm text-slate-600">
        <span class="hidden sm:inline">{{ t('language') }}</span>
        <select :value="language" :aria-label="t('language')" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-800" @change="setLanguage($event.target.value)">
            <option value="en" lang="en">English</option>
            <option value="lo" lang="lo">ລາວ</option>
        </select>
    </label>
</template>
