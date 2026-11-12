<script setup>
import { useId } from 'vue';
import { Icon } from '@iconify/vue';
import englandFlag from '@iconify-icons/circle-flags/gb-eng';
import laosFlag from '@iconify-icons/circle-flags/la';
import { useLocalization } from '../localization/index.js';

// Two-position toggle switch. Built on a radio group so arrow keys and screen readers work.
const { language, setLanguage, t } = useLocalization();
const name = `language-${useId()}`;
const choices = [
    { code: 'en', short: 'EN', label: 'English', flag: englandFlag },
    { code: 'lo', short: 'ລາວ', label: 'ລາວ', flag: laosFlag },
];
</script>

<template>
    <div role="radiogroup" :aria-label="t('language')" :title="t('language')" data-language-switcher class="relative grid h-10 shrink-0 grid-cols-2 items-center rounded-full border border-slate-200 bg-slate-100 p-1 shadow-inner">
        <span aria-hidden="true" data-language-knob class="absolute inset-y-1 left-1 w-[calc(50%-0.25rem)] rounded-full bg-white shadow-sm ring-1 ring-slate-900/5 transition-transform duration-300 ease-out motion-reduce:transition-none" :class="language === 'lo' ? 'translate-x-full' : 'translate-x-0'"></span>
        <label v-for="choice in choices" :key="choice.code" :lang="choice.code" class="relative z-10 flex h-full cursor-pointer items-center justify-center gap-1.5 rounded-full px-3 text-sm transition-colors duration-300 has-focus-visible:ring-2 has-focus-visible:ring-teal-600" :class="language === choice.code ? 'font-semibold text-slate-900' : 'font-medium text-slate-500 hover:text-slate-700'">
            <input type="radio" class="sr-only" :name="name" :value="choice.code" :checked="language === choice.code" @change="setLanguage(choice.code)" />
            <Icon :icon="choice.flag" aria-hidden="true" class="size-5 shrink-0 rounded-full ring-1 ring-slate-900/10 transition-opacity duration-300" :class="{ 'opacity-50 grayscale': language !== choice.code }" />
            <span aria-hidden="true">{{ choice.short }}</span>
            <span class="sr-only">{{ choice.label }}</span>
        </label>
    </div>
</template>
