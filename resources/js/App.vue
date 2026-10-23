<script setup>
import { defineAsyncComponent, watchEffect } from 'vue';
import AppShell from './layouts/AppShell.vue';
import DashboardPage from './pages/DashboardPage.vue';
import PlaceholderPage from './pages/PlaceholderPage.vue';
import { useLocalization } from './localization/index.js';

const UiPreviewPage = defineAsyncComponent(() => import('./pages/UiPreviewPage.vue'));

const props = defineProps({
    page: { type: String, required: true },
    navigation: { type: Array, required: true },
    context: { type: Object, required: true },
});

const { t } = useLocalization();

watchEffect(() => {
    document.title = `${t(props.page)} · DCMS`;
});
</script>

<template>
    <AppShell :page="page" :navigation="navigation" :context="context">
        <DashboardPage v-if="page === 'dashboard'" :navigation="navigation" :context="context" />
        <UiPreviewPage v-else-if="page === 'ui-preview'" />
        <PlaceholderPage v-else :page="page" :dashboard-href="navigation.find(item => item.key === 'dashboard').href" />
    </AppShell>
</template>
