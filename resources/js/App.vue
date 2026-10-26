<script setup>
import { defineAsyncComponent, onMounted, watchEffect } from 'vue';
import { createWorkspace, provideWorkspace } from './utilities/workspace.js';
import AppShell from './layouts/AppShell.vue';
import DashboardPage from './pages/DashboardPage.vue';
import PlaceholderPage from './pages/PlaceholderPage.vue';
import { useLocalization } from './localization/index.js';
import LoginPage from './pages/LoginPage.vue';

const UiPreviewPage = defineAsyncComponent(() => import('./pages/UiPreviewPage.vue'));
const ControlledDocumentsPage = defineAsyncComponent(() => import('./pages/ControlledDocumentsPage.vue'));
const OutgoingPage = defineAsyncComponent(() => import('./pages/OutgoingPage.vue'));
const IncomingPage = defineAsyncComponent(() => import('./pages/IncomingPage.vue'));
const AdministrationPage = defineAsyncComponent(() => import('./pages/AdministrationPage.vue'));

const props = defineProps({
    page: { type: String, required: true },
    navigation: { type: Array, required: true },
    context: { type: Object, required: true },
    auth: { type: Object, default: null },
    incoming: { type: Object, default: null },
    outgoing: { type: Object, default: null },
    controlled: { type: Object, default: null },
    workspace: { type: Object, default: null },
    administration: { type: Object, default: null },
});

const { t } = useLocalization();
const workspaceState = createWorkspace(props.auth?.userId ?? 'guest', props.workspace?.contexts, props.auth?.csrfToken);
provideWorkspace(workspaceState);
onMounted(workspaceState.refresh);

watchEffect(() => {
    document.title = `${t(props.page)} · DCMS`;
});
</script>

<template>
    <LoginPage v-if="page === 'login'" :auth="auth" />
    <AppShell v-else :page="page" :navigation="navigation" :context="context" :auth="auth">
        <DashboardPage v-if="page === 'dashboard'" :navigation="navigation" :context="context" :endpoint="workspace?.dashboard" :csrf-token="auth?.csrfToken" />
        <UiPreviewPage v-else-if="page === 'ui-preview'" />
        <AdministrationPage v-else-if="page === 'administration' && administration" :endpoints="administration" :csrf-token="auth.csrfToken" />
        <ControlledDocumentsPage v-else-if="page === 'controlled-documents' && controlled" :endpoints="controlled" :csrf-token="auth.csrfToken" />
        <OutgoingPage v-else-if="page === 'outgoing' && outgoing" :endpoints="outgoing" :csrf-token="auth.csrfToken" />
        <IncomingPage v-else-if="page === 'incoming' && incoming" :endpoints="incoming" :csrf-token="auth.csrfToken" />
        <PlaceholderPage v-else :page="page" :dashboard-href="navigation.find(item => item.key === 'dashboard').href" />
    </AppShell>
</template>
