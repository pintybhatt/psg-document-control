<script setup>
import { computed, ref } from 'vue';
import { formatWorkspaceLabel, useWorkspace } from '../utilities/workspace.js';
import SelectInput from '../components/ui/SelectInput.vue';
import AppIcon from '../components/AppIcon.vue';
import LanguageSwitcher from '../components/LanguageSwitcher.vue';
import LogoutButton from '../components/LogoutButton.vue';
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
const navigationOpen = ref(false);
const menuButton = ref(null);

function closeNavigation() {
    if (!navigationOpen.value) { return; }
    navigationOpen.value = false;
    menuButton.value?.focus();
}
</script>

<template>
    <div class="min-h-dvh bg-slate-50 text-slate-800">
        <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-lg focus:bg-white focus:p-3">{{ t('skipToContent') }}</a>

        <aside class="fixed inset-y-0 left-0 z-30 hidden w-64 flex-col border-r border-slate-800 bg-slate-950 text-slate-300 lg:flex">
            <a :href="navigation[0].href" class="flex items-center gap-3 px-6 py-7 text-white">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-teal-400 text-sm font-bold tracking-wider text-slate-950">XPPL</span>
                <span><span class="block text-sm font-semibold">XPPL DCMS</span><span class="block text-xs text-slate-400">{{ t('appName') }}</span></span>
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

            </div>
        </aside>

        <div class="lg:pl-64">
            <header class="border-b border-slate-200 bg-white" @keydown.esc="closeNavigation">
                <div class="flex min-h-20 items-center justify-between gap-3 px-4 sm:px-8">
                    <div class="flex min-w-0 flex-1 items-center gap-3">
                        <button ref="menuButton" type="button" :aria-label="navigationOpen ? t('closeNavigation') : t('openNavigation')" :aria-expanded="navigationOpen" aria-controls="mobile-navigation" class="rounded-lg border border-slate-200 p-2 lg:hidden" @click="navigationOpen = !navigationOpen">
                            <AppIcon :name="navigationOpen ? 'close' : 'menu'" class="size-5" />
                        </button>
                        <div class="min-w-0 w-full max-w-sm">
                            <SelectInput v-if="workspace?.sites.value.length > 1" id="workspace-site" v-model="workspace.siteKey.value" :label="t('workspace')" :options="siteOptions" />
                            <template v-else-if="currentSite"><p class="text-xs font-medium text-slate-500">{{ t('workspace') }}</p><p class="truncate text-sm font-semibold" :title="formatWorkspaceLabel(currentSite, language)">{{ formatWorkspaceLabel(currentSite, language) }}</p></template>
                            <template v-else><p class="truncate text-sm font-semibold">{{ context.company.name }}</p>
                            <p class="truncate text-xs text-slate-500">{{ workspace?.loading.value ? t('uiLoading') : t('noSite') }}</p></template>
                        </div>
                    </div>
                    <div class="flex shrink-0 items-center gap-2 sm:gap-4">
                        <LanguageSwitcher />
                        <LogoutButton v-if="auth" :auth="auth" />
                    </div>
                </div>
                <nav id="mobile-navigation" v-show="navigationOpen" :aria-label="t('navigation')" class="grid gap-1 border-t border-slate-200 p-3 sm:grid-cols-2 lg:hidden">
                    <a v-for="item in navigation" :key="item.key" :href="item.href" :aria-current="page === item.key ? 'page' : undefined" class="flex items-center gap-3 rounded-lg p-3 text-sm hover:bg-slate-100" :class="page === item.key ? 'bg-teal-50 font-semibold text-teal-800' : 'text-slate-700'">
                        <AppIcon :name="item.key" class="size-5 shrink-0" />{{ t(item.key) }}
                    </a>
                </nav>
            </header>

            <main id="main-content" tabindex="-1" class="mx-auto flex max-w-7xl flex-col gap-5 px-4 py-6 sm:px-8">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>

                        <h1 class="text-2xl font-semibold tracking-tight">{{ t(page) }}</h1>
                    </div>

                </div>
                <p v-if="workspace?.error.value" role="alert" class="rounded-lg bg-red-50 p-3 text-sm text-red-800">{{ t(workspace.error.value) }}</p>
                <slot />
                <footer class="border-t border-slate-200 pt-5 text-xs text-slate-500">{{ t('footer') }}</footer>
            </main>
        </div>
    </div>
</template>
