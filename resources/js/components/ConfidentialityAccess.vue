<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import UiButton from './ui/UiButton.vue';
import IconButton from './ui/IconButton.vue';
import TextInput from './ui/TextInput.vue';
import SelectInput from './ui/SelectInput.vue';
import DataTable from './ui/DataTable.vue';
import ConfirmDialog from './ui/SweetConfirmDialog.vue';
import { showSuccess } from '../utilities/alerts.js';
import { useLocalization } from '../localization/index.js';
import { createJsonClient } from '../utilities/jsonClient.js';
import { formatDateTime } from '../utilities/dates.js';

const props = defineProps({ scope: { type: Object, required: true }, endpoint: { type: String, required: true }, csrfToken: { type: String, required: true } });
const emit = defineEmits(['unauthorized']);
const { t, language } = useLocalization();
const request = createJsonClient(props.csrfToken);
const contexts = ref([]);
const contextKey = ref('');
const current = computed(() => contexts.value.find(context => String(context.department_id ?? 'site') === contextKey.value));
const can = permission => current.value?.permissions.includes(permission) ?? false;
const levels = computed(() => ['confidential', 'highly_confidential'].filter(level => can(level === 'confidential' ? 'clearances.confidential.manage' : 'clearances.highly-confidential.manage')));
const canDocuments = computed(() => current.value?.can_manage_documents === true);
const userSearch = ref('');
const users = ref([]);
const userId = ref('');
const classification = ref('');
const expiry = ref('');
const operations = ref([]);
const documentSearch = ref('');
const documents = ref([]);
const documentPage = ref(1);
const documentTotal = ref(0);
const selectedDocument = ref(null);
const clearances = ref([]);
const clearancePage = ref(1);
const clearanceTotal = ref(0);
const grants = ref([]);
const grantPage = ref(1);
const grantTotal = ref(0);
const loading = ref(false);
const saving = ref(false);
const errorKey = ref('');
const messageKey = ref('');
const pending = ref(null);
const confirmationOpen = ref(false);
let controller = new AbortController();
const label = item => item?.[language.value === 'lo' ? 'name_lo' : 'name_en'] || item?.name_en || '—';
const contextOptions = computed(() => contexts.value.map(context => ({ value: String(context.department_id ?? 'site'), label: context.department ? label(context.department) : t('confidentialitySiteScope') })));
const userOptions = computed(() => [{ value: '', label: t('uiChoose') }, ...users.value.map(user => ({ value: user.id, label: `${user.name} (${user.email})` }))]);
const levelOptions = computed(() => levels.value.map(level => ({ value: level, label: t(`confidentiality-${level}`) })));
const columns = computed(() => [
    { key: 'account', label: t('rbacAccount') }, { key: 'access', label: t('confidentialityAccess') },
    { key: 'state', label: t('membershipEffectiveStatus') }, { key: 'expiry', label: t('confidentialityExpiry') },
    { key: 'revoked', label: t('confidentialityRevokedAt') }, { key: 'actions', label: t('rbacActions') },
]);
const documentColumns = computed(() => [{ key: 'registerLabel', label: t('confidentialityRegister') }, { key: 'number', label: t('confidentialityNumber') }, { key: 'title', label: t('confidentialityDocument') }, { key: 'actions', label: t('rbacActions') }]);
const documentRows = computed(() => documents.value.map(row => ({ ...row, key: `${row.register}:${row.id}`, registerLabel: t(`confidentialityRegister-${row.register}`), number: row.number || '—', title: language.value === 'lo' ? row.title_lo || row.title_en : row.title_en })));
const selectedDocumentLabel = computed(() => selectedDocument.value ? `${selectedDocument.value.number || '—'} · ${language.value === 'lo' ? selectedDocument.value.title_lo || selectedDocument.value.title_en : selectedDocument.value.title_en}` : '');
const clearanceRows = computed(() => displayRows(clearances.value));
const grantRows = computed(() => displayRows(grants.value));
function displayRows(rows) {
    return rows.map(row => ({ ...row, account: `${row.user.name} (${row.user.email})`, access: t(row.classification ? `confidentiality-${row.classification}` : `documentOperation-${row.operation}`), state: t(`confidentialityStatus-${row.status}`), expiry: formatDateTime(row.expires_at), revoked: formatDateTime(row.revoked_at) }));
}
function scopeData() { return { company_id: props.scope.company_id, site_id: props.scope.site_id, department_id: current.value?.department_id ?? null }; }
function url(path, extra = {}) {
    return `${props.endpoint}/${path}?${new URLSearchParams(Object.entries({ ...scopeData(), ...extra }).filter(([, value]) => value !== null && value !== ''))}`;
}
function handleError(error) {
    if (error.name === 'AbortError') { return; }
    errorKey.value = Object.values(error.fields ?? {}).flat().find(key => /^(confidentiality|documentSecurity)/.test(key)) ?? error.message ?? 'rbacRequestFailed';
    if ([401, 403, 419].includes(error.status)) {
        controller.abort(); contexts.value = []; users.value = []; clearances.value = []; documents.value = []; grants.value = []; selectedDocument.value = null;
        confirmationOpen.value = false;
        emit('unauthorized', error);
    }
}
async function run(operation) {
    const signal = controller.signal;
    loading.value = true; errorKey.value = ''; messageKey.value = '';
    try { await operation(signal); } catch (error) { if (!signal.aborted) { handleError(error); } }
    finally { if (!signal.aborted) { loading.value = false; } }
}
async function loadClearances(signal) {
    if (!levels.value.length) { return; }
    const response = await request(url('clearances', { page: clearancePage.value }), { signal });
    if (!signal.aborted) { clearances.value = response.data; clearanceTotal.value = response.meta.total; }
}
async function loadGrants(signal) {
    if (!selectedDocument.value) { return; }
    const response = await request(url(grantsPath(), { page: grantPage.value }), { signal });
    if (!signal.aborted) { grants.value = response.data; grantTotal.value = response.meta.total; }
}
function grantsPath() { return `documents/${selectedDocument.value.register}/${selectedDocument.value.id}/grants`; }
async function searchUsers() {
    users.value = []; userId.value = '';
    await run(async signal => {
        const response = await request(url('users', { q: userSearch.value, per_page: 50 }), { signal });
        if (!signal.aborted) { users.value = response.data; }
    });
}
async function searchDocuments(reset = true) {
    if (reset) { documentPage.value = 1; }
    selectedDocument.value = null; grants.value = []; documents.value = []; documentTotal.value = 0;
    await run(async signal => {
        const response = await request(url('documents', { search: documentSearch.value, page: documentPage.value }), { signal });
        if (!signal.aborted) { documents.value = response.data; documentTotal.value = response.meta.total; }
    });
}
async function selectDocument(row) {
    selectedDocument.value = row; grants.value = []; grantPage.value = 1; grantTotal.value = 0; operations.value = [];
    await run(loadGrants);
}
async function grant(kind) {
    saving.value = true; errorKey.value = ''; messageKey.value = '';
    const signal = controller.signal;
    try {
        const expiresAt = expiry.value ? new Date(`${expiry.value}+07:00`).toISOString() : null;
        await request(url(kind === 'clearance' ? 'clearances' : grantsPath()), { method: 'POST', signal, body: { ...scopeData(), user_id: Number(userId.value), expires_at: expiresAt, ...(kind === 'clearance' ? { classification: classification.value } : { operations: [...operations.value] }) } });
        if (signal.aborted) { return; }
        if (kind === 'clearance') { clearancePage.value = 1; await loadClearances(signal); } else { grantPage.value = 1; await loadGrants(signal); }
        messageKey.value = 'confidentialitySaved'; showSuccess(t('uiSaveSuccess'), t('confidentialitySaved'));
    } catch (error) { if (!signal.aborted) { handleError(error); } }
    finally { saving.value = false; }
}
function prepareRevoke(kind, row) {
    pending.value = { path: kind === 'clearance' ? `clearances/${row.id}` : `${grantsPath()}/${row.id}`, kind, account: row.account, access: row.access };
    confirmationOpen.value = true;
}
async function revoke() {
    if (!pending.value || saving.value) { return; }
    saving.value = true; errorKey.value = ''; messageKey.value = '';
    const signal = controller.signal;
    try {
        await request(url(pending.value.path), { method: 'DELETE', signal, body: scopeData() });
        if (signal.aborted) { return; }
        if (pending.value.kind === 'clearance') { await loadClearances(signal); } else { await loadGrants(signal); }
        confirmationOpen.value = false; pending.value = null; messageKey.value = 'confidentialityRevoked'; showSuccess(t('uiSaveSuccess'), t('confidentialityRevoked'));
    } catch (error) { if (!signal.aborted) { handleError(error); } }
    finally { saving.value = false; }
}
async function resetScope() {
    controller.abort(); controller = new AbortController();
    userSearch.value = ''; users.value = []; userId.value = ''; expiry.value = ''; operations.value = []; classification.value = levels.value[0] ?? '';
    documentSearch.value = ''; documents.value = []; selectedDocument.value = null; grants.value = []; clearances.value = [];
    clearancePage.value = 1; clearanceTotal.value = 0; grantPage.value = 1; grantTotal.value = 0; documentPage.value = 1; documentTotal.value = 0;
    pending.value = null; confirmationOpen.value = false;
    await run(loadClearances);
}
watch(contextKey, resetScope);
onMounted(() => run(async signal => {
    const response = await request(url('contexts'), { signal });
    if (signal.aborted) { return; }
    contexts.value = response.data; contextKey.value = String(response.data[0]?.department_id ?? 'site');
}));
onBeforeUnmount(() => controller.abort());
function changeClearancePage({ page }) { clearancePage.value = page; run(loadClearances); }
function changeGrantPage({ page }) { grantPage.value = page; run(loadGrants); }
function changeDocumentPage({ page }) { documentPage.value = page; searchDocuments(false); }
</script>

<template>
    <section class="flex flex-col gap-6" aria-labelledby="confidentiality-heading">
        <h3 id="confidentiality-heading" class="text-lg font-semibold">{{ t('confidentialityTitle') }}</h3>
        <p class="text-sm leading-7 text-slate-600">{{ t('confidentialityNotice') }}</p>
        <p v-if="errorKey" role="alert" class="rounded-lg bg-red-50 p-4 text-sm text-red-800">{{ t(errorKey) }}</p>
        <SelectInput v-model="contextKey" :label="t('confidentialityScope')" :helper="t('confidentialityScopeHelp')" :options="contextOptions" :disabled="saving || loading" />
        <template v-if="current">
            <div class="flex flex-col gap-4 rounded-xl border border-slate-200 bg-white p-6">
                <form class="flex flex-wrap items-end gap-3" @submit.prevent="searchUsers">
                    <TextInput v-model="userSearch" :label="t('rbacAccountSearch')" maxlength="200" :disabled="saving || loading" />
                    <UiButton type="submit" variant="secondary" :disabled="saving || loading">{{ t('confidentialityFindUsers') }}</UiButton>
                </form>
                <p class="text-sm text-slate-500">{{ t('confidentialityEligibleUsers') }}</p>
                <SelectInput v-model="userId" :label="t('rbacAccount')" :options="userOptions" :disabled="saving || loading" />
                <TextInput v-model="expiry" type="datetime-local" :label="t('confidentialityExpiry')" :helper="t('confidentialityExpiryHelp')" :disabled="saving || loading" />
            </div>
            <section v-if="levels.length" class="flex flex-col gap-4" aria-labelledby="clearances-heading">
                <h4 id="clearances-heading" class="font-semibold">{{ t('confidentialityClearances') }}</h4>
                <form class="flex flex-wrap items-end gap-3" @submit.prevent="grant('clearance')">
                    <SelectInput v-model="classification" :label="t('confidentialityClassification')" :options="levelOptions" :disabled="saving || loading" />
                    <UiButton type="submit" :disabled="saving || loading || !userId || !classification">{{ t('confidentialityGrantClearance') }}</UiButton>
                </form>
                <DataTable v-model:page="clearancePage" :rows="clearanceRows" :columns="columns" :caption="t('confidentialityClearances')" :loading="loading" server-side :total="clearanceTotal" :per-page="10" @change="changeClearancePage">
                    <template #cell-actions="{ row }"><IconButton v-if="row.revocable" action="delete" :label="t('confidentialityRevoke')" :disabled="saving || loading" @click="prepareRevoke('clearance', row)" /></template>
                </DataTable>
            </section>
            <section v-if="canDocuments" class="flex flex-col gap-4" aria-labelledby="document-access-heading">
                <h4 id="document-access-heading" class="font-semibold">{{ t('confidentialityDocuments') }}</h4>
                <p class="text-sm leading-7 text-slate-500">{{ t('confidentialityDocumentsHelp') }}</p>
                <form class="flex flex-wrap items-end gap-3" @submit.prevent="searchDocuments()">
                    <TextInput v-model="documentSearch" :label="t('confidentialityDocumentSearch')" maxlength="200" :disabled="saving || loading" />
                    <UiButton type="submit" variant="secondary" :disabled="saving || loading">{{ t('confidentialityFindDocuments') }}</UiButton>
                </form>
                <DataTable v-model:page="documentPage" :rows="documentRows" :columns="documentColumns" :caption="t('confidentialityDocuments')" :loading="loading" server-side :total="documentTotal" :per-page="10" @change="changeDocumentPage">
                    <template #cell-actions="{ row }"><UiButton variant="secondary" :disabled="saving || loading" @click="selectDocument(row)">{{ t('confidentialityManageAccess') }}</UiButton></template>
                </DataTable>
                <template v-if="selectedDocument">
                    <h5 class="font-semibold wrap-anywhere">{{ selectedDocumentLabel }}</h5>
                    <form class="flex flex-col gap-4 rounded-xl border border-slate-200 bg-white p-6" @submit.prevent="grant('document')">
                        <fieldset :disabled="saving || loading" class="flex flex-wrap gap-4">
                            <legend class="mb-3 text-sm font-semibold">{{ t('confidentialityOperations') }}</legend>
                            <label v-for="operation in ['metadata', 'preview', 'download']" :key="operation" class="flex items-center gap-2 text-sm"><input v-model="operations" type="checkbox" :value="operation" class="size-4 accent-teal-700" />{{ t(`documentOperation-${operation}`) }}</label>
                        </fieldset>
                        <div><UiButton type="submit" :disabled="saving || loading || !userId || !operations.length">{{ t('confidentialityGrantDocument') }}</UiButton></div>
                    </form>
                    <DataTable v-model:page="grantPage" :rows="grantRows" :columns="columns" :caption="t('confidentialityExistingGrants')" :loading="loading" server-side :total="grantTotal" :per-page="10" @change="changeGrantPage">
                        <template #cell-actions="{ row }"><IconButton v-if="row.revocable" action="delete" :label="t('confidentialityRevoke')" :disabled="saving || loading" @click="prepareRevoke('document', row)" /></template>
                    </DataTable>
                </template>
            </section>
            <p v-else-if="can('document-access.manage')" class="text-sm text-slate-600">{{ t('confidentialityMetadataRequired') }}</p>
        </template>
        <ConfirmDialog v-model="confirmationOpen" danger :title="t('confidentialityRevoke')" :message="`${t('confidentialityRevokeConfirm')} ${pending?.account ?? ''} · ${pending?.access ?? ''}`" :loading="saving" @confirm="revoke" />
    </section>
</template>
