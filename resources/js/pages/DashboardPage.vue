<script setup>
import AppIcon from '../components/AppIcon.vue';
import { useLocalization } from '../localization/index.js';

defineProps({
    navigation: { type: Array, required: true },
    context: { type: Object, required: true },
});

const { t } = useLocalization();
</script>

<template>
    <section class="relative overflow-hidden rounded-2xl bg-slate-900 p-6 text-white sm:p-9">
        <div class="pointer-events-none absolute -top-24 -right-16 size-72 rounded-full border-40 border-teal-400/10" aria-hidden="true"></div>
        <div class="relative max-w-2xl">
            <p class="mb-4 text-xs font-semibold tracking-widest text-teal-300">PHONESACK GROUP</p>
            <h2 class="text-2xl leading-relaxed font-semibold tracking-tight sm:text-3xl">{{ t('welcome') }}</h2>
            <p class="mt-4 max-w-xl text-sm leading-7 text-slate-300">{{ t('introduction') }}</p>
        </div>
    </section>

    <section aria-labelledby="modules-heading">
        <h2 id="modules-heading" class="text-lg font-semibold">{{ t('modules') }}</h2>
        <p class="mt-2 text-sm leading-relaxed text-slate-500">{{ t('modulesDescription') }}</p>
        <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <a v-for="item in navigation.filter(item => item.key !== 'dashboard')" :key="item.key" :href="item.href" class="group flex flex-col gap-5 rounded-xl border border-slate-200 bg-white p-5 transition-colors hover:border-teal-500 motion-reduce:transition-none">
                <div class="flex items-center justify-between gap-3">
                    <span class="rounded-lg bg-slate-100 p-2.5 text-slate-600 group-hover:bg-teal-50 group-hover:text-teal-800"><AppIcon :name="item.key" class="size-5" /></span>
                    <span class="text-xs text-slate-500">{{ t('planned') }}</span>
                </div>
                <div class="flex-1">
                    <h3 class="text-sm font-semibold">{{ t(item.key) }}</h3>
                    <p class="mt-2 text-sm leading-6 text-slate-500">{{ t(`${item.key}Description`) }}</p>
                </div>
                <AppIcon name="arrow" class="size-4 self-end text-slate-400 group-hover:text-teal-700" />
            </a>
        </div>
    </section>

    <section aria-labelledby="context-heading" class="rounded-xl border border-slate-200 bg-white p-6">
        <div class="flex items-center gap-3">
            <AppIcon name="organization" class="size-5 text-teal-700" />
            <h2 id="context-heading" class="text-base font-semibold">{{ t('contextTitle') }}</h2>
        </div>
        <p class="mt-3 text-sm leading-relaxed text-slate-500">{{ t('contextDescription') }}</p>
        <dl class="mt-5 grid gap-5 sm:grid-cols-2">
            <div><dt class="text-xs text-slate-500">{{ t('company') }}</dt><dd class="mt-1 text-sm font-medium">{{ context.company.name }}</dd></div>
            <div><dt class="text-xs text-slate-500">{{ t('site') }}</dt><dd class="mt-1 text-sm font-medium">{{ context.site?.name ?? t('noSite') }}</dd></div>
        </dl>
    </section>
</template>
