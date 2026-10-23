<script setup>
import { ref } from 'vue';
import AppIcon from '../components/AppIcon.vue';
import LanguageSwitcher from '../components/LanguageSwitcher.vue';
import { useLocalization } from '../localization/index.js';

defineProps({
    page: { type: String, required: true },
    navigation: { type: Array, required: true },
    context: { type: Object, required: true },
});

const { t } = useLocalization();
const navigationOpen = ref(false);
const menuButton = ref(null);

function closeNavigation() {
    navigationOpen.value = false;
    menuButton.value?.focus();
}
</script>

<template>
    <div class="min-h-dvh bg-slate-50 text-slate-800">
        <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-lg focus:bg-white focus:p-3">{{ t('skipToContent') }}</a>

        <aside class="fixed inset-y-0 left-0 z-30 hidden w-64 flex-col border-r border-slate-800 bg-slate-950 text-slate-300 lg:flex">
            <a :href="navigation[0].href" class="flex items-center gap-3 px-6 py-7 text-white">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-teal-400 text-sm font-bold tracking-wider text-slate-950">PSG</span>
                <span><span class="block text-sm font-semibold">Phonesack Group</span><span class="block text-xs text-slate-400">{{ t('appName') }}</span></span>
            </a>
            <p class="px-6 pt-5 pb-3 text-xs font-semibold tracking-wider text-slate-400">{{ t('workspace') }}</p>
            <nav :aria-label="t('navigation')" class="flex flex-col gap-1 px-3">
                <a v-for="item in navigation" :key="item.key" :href="item.href" :aria-current="page === item.key ? 'page' : undefined" class="flex items-center gap-3 rounded-lg px-3 py-3 text-sm font-medium hover:bg-slate-800 hover:text-white" :class="page === item.key ? 'bg-teal-400/10 text-teal-300' : 'text-slate-300'">
                    <AppIcon :name="item.key" class="size-5 shrink-0" />
                    {{ t(item.key) }}
                </a>
            </nav>
            <div class="mt-auto border-t border-slate-800 p-6">
                <p class="text-xs leading-relaxed text-slate-400">{{ t('appDescription') }}</p>
                <p class="mt-3 text-xs font-medium text-teal-300">{{ t('foundation') }}</p>
            </div>
        </aside>

        <div class="lg:pl-64">
            <header class="border-b border-slate-200 bg-white">
                <div class="flex min-h-20 items-center justify-between gap-3 px-4 sm:px-8">
                    <div class="flex min-w-0 items-center gap-3">
                        <button ref="menuButton" type="button" :aria-label="navigationOpen ? t('closeNavigation') : t('openNavigation')" :aria-expanded="navigationOpen" aria-controls="mobile-navigation" class="rounded-lg border border-slate-200 p-2 lg:hidden" @click="navigationOpen = !navigationOpen">
                            <AppIcon :name="navigationOpen ? 'close' : 'menu'" class="size-5" />
                        </button>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold">{{ context.company.name }}</p>
                            <p class="truncate text-xs text-slate-500">{{ context.site?.name ?? t('noSite') }}</p>
                        </div>
                    </div>
                    <LanguageSwitcher />
                </div>
                <nav id="mobile-navigation" v-show="navigationOpen" :aria-label="t('navigation')" class="grid gap-1 border-t border-slate-200 p-3 sm:grid-cols-2 lg:hidden" @keydown.esc="closeNavigation">
                    <a v-for="item in navigation" :key="item.key" :href="item.href" :aria-current="page === item.key ? 'page' : undefined" class="flex items-center gap-3 rounded-lg p-3 text-sm hover:bg-slate-100" :class="page === item.key ? 'bg-teal-50 font-semibold text-teal-800' : 'text-slate-700'">
                        <AppIcon :name="item.key" class="size-5 shrink-0" />{{ t(item.key) }}
                    </a>
                </nav>
            </header>

            <main id="main-content" tabindex="-1" class="mx-auto flex max-w-7xl flex-col gap-8 px-4 py-8 sm:px-8 sm:py-10">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="mb-2 text-xs font-medium text-slate-500">DCMS / {{ t('workspace') }}</p>
                        <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ t(page) }}</h1>
                    </div>
                    <span class="rounded-full border border-teal-200 bg-teal-50 px-3 py-1.5 text-xs font-medium text-teal-800">{{ t('foundation') }}</span>
                </div>
                <slot />
                <footer class="border-t border-slate-200 pt-5 text-xs text-slate-500">{{ t('footer') }}</footer>
            </main>
        </div>
    </div>
</template>
