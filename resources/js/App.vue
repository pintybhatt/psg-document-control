<script setup>
import { defineAsyncComponent, onMounted, reactive, watchEffect } from 'vue';
import { createWorkspace, provideWorkspace } from './utilities/workspace.js';
import AppShell from './layouts/AppShell.vue';
import DashboardPage from './pages/DashboardPage.vue';
import PlaceholderPage from './pages/PlaceholderPage.vue';
import { useLocalization } from './localization/index.js';
import LoginPage from './pages/LoginPage.vue';

const UiPreviewPage = defineAsyncComponent(() => import('./pages/UiPreviewPage.vue'));
const ControlledDocumentsPage = defineAsyncComponent(() => import('./pages/ControlledDocumentsPage.vue'));
const OutgoingPage = defineAsyncComponent(() => import('./pages/OutgoingPage.vue'));
const ReportsPage = defineAsyncComponent(() => import('./pages/ReportsPage.vue'));
const DocumentSearchPage = defineAsyncComponent(() => import('./pages/DocumentSearchPage.vue'));
const IncomingPage = defineAsyncComponent(() => import('./pages/IncomingPage.vue'));
const AdministrationPage = defineAsyncComponent(() => import('./pages/AdministrationPage.vue'));
const ProfilePage = defineAsyncComponent(() => import('./pages/ProfilePage.vue'));

const props = defineProps({
    page: { type: String, required: true },
    navigation: { type: Array, required: true },
    context: { type: Object, required: true },
    auth: { type: Object, default: null },
    incoming: { type: Object, default: null },
    outgoing: { type: Object, default: null },
    controlled: { type: Object, default: null },
    search: { type: Object, default: null },
    reports: { type: Object, default: null },
    workspace: { type: Object, default: null },
    administration: { type: Object, default: null },
    profile: { type: Object, default: null },
});

const { t } = useLocalization();
// Reactive copy so a new profile photo shows in the header without a reload.
const session = props.auth ? reactive({ ...props.auth, user: props.auth.user ? { ...props.auth.user } : null }) : null;
function setPhoto(url) {
    if (session?.user) { session.user.photoUrl = url; }
}
const workspaceState = createWorkspace(props.auth?.userId ?? 'guest', props.workspace?.contexts, props.auth?.csrfToken);
provideWorkspace(workspaceState);
onMounted(workspaceState.refresh);

watchEffect(() => {
    document.title = `${t(props.page)} · DCMS`;
});
</script>

<template>
    <LoginPage v-if="page === 'login'" :auth="session" />
    <AppShell v-else :page="page" :navigation="navigation" :context="context" :auth="session">
        <DashboardPage v-if="page === 'dashboard'" :navigation="navigation" :context="context" :endpoint="workspace?.dashboard" :csrf-token="session?.csrfToken" />
        <UiPreviewPage v-else-if="page === 'ui-preview'" />
        <AdministrationPage v-else-if="page === 'administration' && administration" :endpoints="administration" :csrf-token="session.csrfToken" />
        <ControlledDocumentsPage v-else-if="page === 'controlled-documents' && controlled" :endpoints="controlled" :csrf-token="session.csrfToken" />
        <OutgoingPage v-else-if="page === 'outgoing' && outgoing" :endpoints="outgoing" :csrf-token="session.csrfToken" />
        <IncomingPage v-else-if="page === 'incoming' && incoming" :endpoints="incoming" :csrf-token="session.csrfToken" />
        <DocumentSearchPage v-else-if="page === 'document-search' && search" :endpoint="search.endpoint" :csrf-token="session.csrfToken" />
        <ReportsPage v-else-if="page === 'reports' && reports" :endpoint="reports.endpoint" :csrf-token="session.csrfToken" />
        <ProfilePage v-else-if="page === 'profile' && profile && session?.user" :user="session.user" :profile="profile" :photo-endpoint="session.profilePhotoEndpoint" :password-endpoint="session.profilePasswordEndpoint" :csrf-token="session.csrfToken" @photo-changed="setPhoto" />
        <PlaceholderPage v-else :page="page" :dashboard-href="navigation.find(item => item.key === 'dashboard').href" />
    </AppShell>
</template>
