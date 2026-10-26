<script setup>
import { defineAsyncComponent, watchEffect } from 'vue';
import AppShell from './layouts/AppShell.vue';
import DashboardPage from './pages/DashboardPage.vue';
import PlaceholderPage from './pages/PlaceholderPage.vue';
import { useLocalization } from './localization/index.js';
import LoginPage from './pages/LoginPage.vue';

const UiPreviewPage = defineAsyncComponent(() => import('./pages/UiPreviewPage.vue'));
const IncomingPage = defineAsyncComponent(() => import('./pages/IncomingPage.vue'));
const AdministrationPage = defineAsyncComponent(() => import('./pages/AdministrationPage.vue'));

const props = defineProps({
    page: { type: String, required: true },
    navigation: { type: Array, required: true },
    context: { type: Object, required: true },
    auth: { type: Object, default: null },
    incoming: { type: Object, default: null },
    administration: { type: Object, default: null },
});

const { t } = useLocalization();

watchEffect(() => {
    document.title = `${t(props.page)} · DCMS`;
});
</script>

<template>
    <LoginPage v-if="page === 'login'" :auth="auth" />
    <AppShell v-else :page="page" :navigation="navigation" :context="context" :auth="auth">
        <DashboardPage v-if="page === 'dashboard'" :navigation="navigation" :context="context" />
        <UiPreviewPage v-else-if="page === 'ui-preview'" />
        <AdministrationPage v-else-if="page === 'administration' && administration" :endpoints="administration" :csrf-token="auth.csrfToken" />
        <IncomingPage v-else-if="page === 'incoming' && incoming" :endpoints="incoming" :csrf-token="auth.csrfToken" />
        <PlaceholderPage v-else :page="page" :dashboard-href="navigation.find(item => item.key === 'dashboard').href" />
    </AppShell>
</template>
