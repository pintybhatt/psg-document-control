<script setup>
import { computed, ref, watch } from 'vue';
import { Icon } from '@iconify/vue';
import panelLeftCloseIcon from '@iconify-icons/flowbite/close-sidebar-outline';
import panelLeftOpenIcon from '@iconify-icons/flowbite/open-sidebar-outline';
import { formatWorkspaceLabel, useWorkspace } from '../utilities/workspace.js';
import { useSelect2 } from '../utilities/select2.js';
import buildingIcon from '@iconify-icons/flowbite/building-outline';
import documentIcon from '@iconify-icons/flowbite/file-lines-solid';
import documentBackIcon from '@iconify-icons/flowbite/file-solid';
import AppIcon from '../components/AppIcon.vue';
import LanguageSwitcher from '../components/LanguageSwitcher.vue';
import UserMenu from '../components/UserMenu.vue';
import { useLocalization } from '../localization/index.js';

defineProps({
    page: { type: String, required: true },
    navigation: { type: Array, required: true },
    context: { type: Object, required: true },
    auth: { type: Object, default: null },
});

const { t, language } = useLocalization();
const workspace = useWorkspace();
const siteOptions = computed(() => workspace?.sites.value.map(item => ({ value: `${item.company_id}:${item.site_id}`, label: formatWorkspaceLabel(item, language.value) })) ?? []);
const currentSite = computed(() => workspace?.sites.value.find(item => `${item.company_id}:${item.site_id}` === workspace.siteKey.value));
const workspaceSelect = ref(null);
useSelect2(workspaceSelect, { sources: [() => workspace?.siteKey.value, () => siteOptions.value], selectionClass: 'dcms-select2-workspace' });
const navigationOpen = ref(false);
const menuButton = ref(null);
const collapseStorageKey = 'dcms.sidebarCollapsed';
const sidebarCollapsed = ref(readCollapsed());

function readCollapsed() {
    try { return window.localStorage.getItem(collapseStorageKey) === '1'; } catch { return false; }
}

watch(sidebarCollapsed, (value) => {
    try { window.localStorage.setItem(collapseStorageKey, value ? '1' : '0'); } catch { /* Storage unavailable: keep the in-memory choice. */ }
});

function closeNavigation() {
    if (!navigationOpen.value) { return; }
    navigationOpen.value = false;
    menuButton.value?.focus();
}
</script>

<template>
    <div class="isolate min-h-dvh bg-slate-50 text-slate-800">
        <div aria-hidden="true" data-app-backdrop class="pointer-events-none fixed inset-0 -z-10 overflow-hidden bg-linear-to-br from-slate-50 via-teal-50/60 to-sky-100/70">
            <div class="absolute -top-40 right-[-10rem] size-[36rem] rounded-full bg-teal-300/25 blur-3xl"></div>
            <div class="absolute top-1/3 left-1/4 size-[28rem] rounded-full bg-sky-300/20 blur-3xl"></div>
            <div class="absolute -bottom-48 right-1/4 size-[32rem] rounded-full bg-emerald-200/30 blur-3xl"></div>
            <div class="absolute inset-0 bg-[radial-gradient(circle,rgb(15_23_42/0.05)_1px,transparent_1px)] bg-size-[24px_24px]"></div>
        </div>
        <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-lg focus:bg-white focus:p-3">{{ t('skipToContent') }}</a>

        <aside id="desktop-navigation" class="fixed inset-y-0 left-0 z-30 hidden flex-col overflow-hidden border-r border-white/10 bg-linear-to-b from-slate-950 via-[#06213a] to-teal-950 text-slate-300 transition-[width] duration-200 motion-reduce:transition-none lg:flex" :class="sidebarCollapsed ? 'w-20' : 'w-64'">
            <div aria-hidden="true" class="pointer-events-none absolute inset-0 -z-10">
                <div class="absolute -top-24 -left-20 size-64 rounded-full bg-teal-500/20 blur-3xl"></div>
                <div class="absolute top-1/2 -right-24 size-64 rounded-full bg-sky-500/15 blur-3xl"></div>
                <div class="absolute -bottom-24 -left-10 size-72 rounded-full bg-emerald-400/15 blur-3xl"></div>
            </div>
            <a :href="navigation[0].href" class="flex items-center gap-3 border-b border-white/10 py-6 text-white" :class="sidebarCollapsed ? 'justify-center' : 'px-6'" :title="sidebarCollapsed ? 'XPPL DCMS' : undefined">
                <img src="/logo/xppl.png" alt="XPPL" class="size-10 shrink-0 rounded-xl object-contain" />
                <span :class="sidebarCollapsed ? 'sr-only' : 'min-w-0'"><span class="block text-sm font-semibold">XPPL DCMS</span><span class="block text-xs text-slate-400">{{ t('appName') }}</span></span>
            </a>
            <p class="px-6 pt-5 pb-3 text-xs font-semibold tracking-wider text-slate-400" :class="{ 'sr-only': sidebarCollapsed }">{{ t('workspace') }}</p>
            <nav :aria-label="t('navigation')" class="flex flex-col gap-1 px-3" :class="{ 'pt-5': sidebarCollapsed }">
                <a v-for="item in navigation" :key="item.key" :href="item.href" :aria-current="page === item.key ? 'page' : undefined" :title="sidebarCollapsed ? t(item.key) : undefined" class="flex items-center gap-3 rounded-xl py-3 text-sm font-medium transition-colors" :class="[page === item.key ? 'bg-white/10 text-white shadow-lg shadow-black/10 ring-1 ring-white/15 backdrop-blur-md' : 'text-slate-300 hover:bg-white/5 hover:text-white', sidebarCollapsed ? 'justify-center' : 'px-3']">
                    <AppIcon :name="item.key" class="size-5 shrink-0" :class="{ 'text-teal-300': page === item.key }" />
                    <span :class="{ 'sr-only': sidebarCollapsed }">{{ t(item.key) }}</span>
                </a>
            </nav>
            <div v-if="!sidebarCollapsed" aria-hidden="true" data-sidebar-illustration class="mt-auto p-4">
                <div class="relative flex h-40 items-center justify-center overflow-hidden rounded-3xl border border-white/10 bg-white/5 shadow-2xl shadow-black/20 ring-1 ring-white/5 backdrop-blur-xl">
                    <div class="absolute size-32 rounded-full bg-teal-400/20 blur-2xl"></div>
                    <Icon :icon="documentBackIcon" class="absolute size-24 translate-x-5 -translate-y-2 rotate-12 text-sky-300/30" />
                    <Icon :icon="documentIcon" class="relative size-24 -rotate-6 text-teal-300/80 drop-shadow-[0_8px_16px_rgb(20_184_166/0.35)]" />
                </div>
            </div>
        </aside>

        <div class="transition-[padding] duration-200 motion-reduce:transition-none" :class="sidebarCollapsed ? 'lg:pl-20' : 'lg:pl-64'">
            <header class="sticky top-0 z-30 border-b border-white/70 bg-white/60 shadow-[0_1px_0_rgb(15_23_42/0.04)] backdrop-blur-xl backdrop-saturate-150" @keydown.esc="closeNavigation">
                <div class="flex h-16 items-center gap-3 px-4 sm:px-6 lg:px-8">
                    <div class="flex min-w-0 flex-1 items-center gap-2 sm:gap-3">
                        <button ref="menuButton" type="button" :aria-label="navigationOpen ? t('closeNavigation') : t('openNavigation')" :title="navigationOpen ? t('closeNavigation') : t('openNavigation')" :aria-expanded="navigationOpen" aria-controls="mobile-navigation" class="inline-flex size-10 shrink-0 items-center justify-center rounded-xl text-slate-600 hover:bg-slate-100 hover:text-slate-900 lg:hidden" @click="navigationOpen = !navigationOpen">
                            <AppIcon :name="navigationOpen ? 'close' : 'menu'" class="size-5" />
                        </button>
                        <button type="button" :aria-label="sidebarCollapsed ? t('expandNavigation') : t('collapseNavigation')" :title="sidebarCollapsed ? t('expandNavigation') : t('collapseNavigation')" :aria-expanded="!sidebarCollapsed" aria-controls="desktop-navigation" class="hidden size-10 shrink-0 items-center justify-center rounded-xl text-slate-600 hover:bg-slate-100 hover:text-slate-900 lg:inline-flex" @click="sidebarCollapsed = !sidebarCollapsed">
                            <Icon :icon="sidebarCollapsed ? panelLeftOpenIcon : panelLeftCloseIcon" aria-hidden="true" class="size-5" />
                        </button>
                        <span class="hidden h-6 w-px shrink-0 bg-slate-200 lg:block" aria-hidden="true"></span>
                        <div v-if="workspace?.sites.value.length > 1" data-workspace-switcher class="relative min-w-0 w-full max-w-[38rem]">
                            <label for="workspace-site" class="sr-only">{{ t('workspace') }}</label>
                            <span aria-hidden="true" class="pointer-events-none absolute top-1/2 left-1.5 z-10 inline-flex size-7 -translate-y-1/2 items-center justify-center rounded-lg bg-teal-50 text-teal-700 ring-1 ring-teal-100"><Icon :icon="buildingIcon" class="size-4" /></span>
                            <select id="workspace-site" ref="workspaceSelect" v-model="workspace.siteKey.value" :title="t('workspace')" class="h-10 w-full appearance-none truncate rounded-xl border border-slate-200 bg-slate-50 py-0 pr-9 pl-10 text-sm font-semibold text-slate-800">
                                <option v-for="option in siteOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                            </select>
                        </div>
                        <div v-else class="flex min-w-0 items-center gap-3">
                            <span class="hidden size-10 shrink-0 items-center justify-center rounded-xl bg-teal-50 text-teal-700 ring-1 ring-teal-100 sm:inline-flex" aria-hidden="true"><Icon :icon="buildingIcon" class="size-5" /></span>
                            <div class="min-w-0">
                                <template v-if="currentSite"><p class="text-xs font-medium text-slate-500">{{ t('workspace') }}</p><p class="truncate text-sm font-semibold text-slate-800" :title="formatWorkspaceLabel(currentSite, language)">{{ formatWorkspaceLabel(currentSite, language) }}</p></template>
                                <template v-else><p class="truncate text-sm font-semibold text-slate-800">{{ context.company.name }}</p>
                                <p class="truncate text-xs text-slate-500">{{ workspace?.loading.value ? t('uiLoading') : t('noSite') }}</p></template>
                            </div>
                        </div>
                    </div>
                    <div class="flex shrink-0 items-center gap-2 sm:gap-3">
                        <LanguageSwitcher />
                        <template v-if="auth">
                            <span class="hidden h-6 w-px bg-slate-200 sm:block" aria-hidden="true"></span>
                            <UserMenu :auth="auth" />
                        </template>
                    </div>
                </div>
                <nav id="mobile-navigation" v-show="navigationOpen" :aria-label="t('navigation')" class="grid gap-1 border-t border-slate-200 p-3 sm:grid-cols-2 lg:hidden">
                    <a v-for="item in navigation" :key="item.key" :href="item.href" :aria-current="page === item.key ? 'page' : undefined" class="flex items-center gap-3 rounded-lg p-3 text-sm hover:bg-slate-100" :class="page === item.key ? 'bg-teal-50 font-semibold text-teal-800' : 'text-slate-700'">
                        <AppIcon :name="item.key" class="size-5 shrink-0" />{{ t(item.key) }}
                    </a>
                </nav>
            </header>

            <main id="main-content" tabindex="-1" class="dcms-glass mx-auto flex max-w-7xl flex-col gap-6 px-4 py-6 sm:px-8 lg:py-8">
                <div data-page-header class="flex items-center gap-4">
                    <span aria-hidden="true" class="inline-flex size-12 shrink-0 items-center justify-center rounded-2xl bg-linear-to-br from-teal-500 to-sky-600 text-white shadow-lg shadow-teal-700/25 ring-1 ring-white/40">
                        <AppIcon :name="page" class="size-6" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-semibold tracking-wider text-teal-700 uppercase">{{ t('appName') }}</p>
                        <h1 class="truncate text-2xl font-semibold tracking-tight text-slate-900 sm:text-3xl">{{ t(page) }}</h1>
                    </div>
                    <!-- Page-level actions (e.g. the Back button) are teleported here so they line up with the title. -->
                    <div data-page-header-actions class="flex shrink-0 items-center gap-2 empty:hidden"></div>
                </div>
                <p v-if="workspace?.error.value" role="alert" class="rounded-xl border border-red-200 bg-red-50/90 p-3 text-sm text-red-800 backdrop-blur">{{ t(workspace.error.value) }}</p>
                <slot />
                <footer class="border-t border-slate-900/5 pt-5 text-xs text-slate-500">{{ t('footer') }}</footer>
            </main>
        </div>
    </div>
</template>
