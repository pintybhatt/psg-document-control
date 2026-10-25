<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import UiButton from '../components/ui/UiButton.vue';
import TextInput from '../components/ui/TextInput.vue';
import TextareaInput from '../components/ui/TextareaInput.vue';
import SelectInput from '../components/ui/SelectInput.vue';
import CheckboxInput from '../components/ui/CheckboxInput.vue';
import DataTable from '../components/ui/DataTable.vue';
import ConfirmDialog from '../components/ui/ConfirmDialog.vue';
import ToastNotifications from '../components/ui/ToastNotifications.vue';
import { useNotifications } from '../components/ui/useNotifications.js';
import { useLocalization } from '../localization/index.js';
import { createJsonClient } from '../utilities/jsonClient.js';

const props = defineProps({ endpoints: { type: Object, required: true }, csrfToken: { type: String, required: true } });
const { t, language } = useLocalization();
const request = createJsonClient(props.csrfToken);
const { items, notify, dismiss } = useNotifications();
const contexts = ref([]);
const selectedKey = ref('');
const selected = computed(() => contexts.value.find(scope => scope.scope_key === selectedKey.value));
const can = permission => selected.value?.permissions.includes(permission) ?? false;
const loading = ref(true);
const saving = ref(false);
const errorKey = ref('');
const fields = ref({});
const roles = ref([]);
const permissions = ref([]);
const assignments = ref([]);
const rolePage = ref(1);
const roleTotal = ref(0);
const assignmentPage = ref(1);
const assignmentTotal = ref(0);
const users = ref([]);
const userSearch = ref('');
const roleSearch = ref('');
const roleOptions = ref([]);
const targetUser = ref('');
const targetRole = ref('');
const editorOpen = ref(false);
const editingRole = ref(null);
const readOnly = computed(() => editingRole.value !== null && !editingRole.value.editable);
const securityReadOnly = computed(() => readOnly.value || editingRole.value?.security_editable === false);
const roleForm = reactive({ code: '', name_en: '', name_lo: '', description: '', active: true, permission_keys: [] });
const confirmationOpen = ref(false);
const pending = ref(null);
const searchLoading = ref(false);
let scopeController;
let disposed = false;

const label = record => record?.[language.value === 'lo' ? 'name_lo' : 'name_en'] ?? '';
const scopeLabel = scope => scope.scope_type === 'global' ? t('rbacGlobal')
    : `${t(scope.scope_type === 'company' ? 'company' : 'site')}: ${label(scope.company)}${scope.site ? ` / ${label(scope.site)}` : ''}`;
const scopeOptions = computed(() => contexts.value.map(scope => ({ value: scope.scope_key, label: scopeLabel(scope) })));
const roleRows = computed(() => roles.value.map(role => ({ ...role, display_name: label(role), status: t(role.active ? 'rbacActive' : 'rbacInactive') })));
const roleColumns = computed(() => [
    { key: 'code', label: t('rbacCode'), type: 'reference' }, { key: 'display_name', label: t('rbacRole') },
    { key: 'status', label: t('uiStatus') }, { key: 'actions', label: t('rbacActions') },
]);
const assignmentRows = computed(() => assignments.value.map(assignment => ({ ...assignment, user_name: assignment.user.name, role_name: label(assignment.role), status: t(assignment.active ? 'rbacActive' : 'rbacInactive') })));
const assignmentColumns = computed(() => [
    { key: 'user_name', label: t('rbacAccount') }, { key: 'role_name', label: t('rbacRole') },
    { key: 'scope_key', label: t('rbacScope') }, { key: 'status', label: t('uiStatus') }, { key: 'actions', label: t('rbacActions') },
]);
const permissionGroups = computed(() => Object.entries(permissions.value.reduce((groups, permission) => {
    (groups[permission.area] ??= []).push(permission);
    return groups;
}, {})));
const candidateUsers = computed(() => [{ value: '', label: t('uiChoose') }, ...users.value.map(user => ({ value: user.id, label: `${user.name} (${user.email})` }))]);
const candidateRoles = computed(() => [{ value: '', label: t('uiChoose') }, ...roleOptions.value.filter(role => role.active && role.permission_keys.every(key => permissions.value.some(permission => permission.key === key && permission.available && permission.delegatable))).map(role => ({ value: role.id, label: `${label(role)} (${role.code})` }))]);
const confirmationMessage = computed(() => {
    const action = pending.value;
    if (!action || !selected.value) { return ''; }
    return `${t(action.kind === 'assign' ? 'rbacAssignConfirm' : 'rbacRevokeConfirm')} ${action.account} · ${label(action.role)} (${action.role.code}) · ${scopeLabel(selected.value)}`;
});

function scopeData() {
    return { scope_type: selected.value.scope_type, company_id: selected.value.company_id, site_id: selected.value.site_id };
}

function url(endpoint, extra = {}) {
    const parameters = Object.entries({ ...scopeData(), ...extra }).filter(([, value]) => value !== null && value !== undefined && value !== '');
    return `${endpoint}?${new URLSearchParams(parameters)}`;
}

function handleError(error) {
    if (error.name === 'AbortError' || disposed) { return; }
    fields.value = error.fields ?? {};
    const known = Object.values(fields.value).flat().find(message => typeof message === 'string' && message.startsWith('rbac'));
    errorKey.value = known ?? (error.message.startsWith('rbac') ? error.message : 'rbacRequestFailed');
    if ([401, 403, 419].includes(error.status)) {
        scopeController?.abort();
        loading.value = false; searchLoading.value = false;
        roles.value = []; assignments.value = []; users.value = []; permissions.value = []; roleOptions.value = [];
        if (selected.value) { selected.value.permissions = []; }
        editorOpen.value = false;
        confirmationOpen.value = false;
    }
}

async function loadRoles(signal = scopeController?.signal) {
    if (!can('roles.view')) { return; }
    const response = await request(url(props.endpoints.roles, { page: rolePage.value, per_page: 10 }), { signal });
    roles.value = response.data; roleTotal.value = response.meta.total;
}

async function loadAssignments(signal = scopeController?.signal) {
    if (!can('assignments.view')) { return; }
    const response = await request(url(props.endpoints.assignments, { page: assignmentPage.value, per_page: 10 }), { signal });
    assignments.value = response.data; assignmentTotal.value = response.meta.total;
}

async function loadScope() {
    scopeController?.abort();
    scopeController = new AbortController();
    const signal = scopeController.signal;
    roles.value = []; assignments.value = []; permissions.value = []; users.value = []; roleOptions.value = [];
    roleTotal.value = 0; assignmentTotal.value = 0; rolePage.value = 1; assignmentPage.value = 1;
    targetUser.value = ''; targetRole.value = ''; userSearch.value = ''; roleSearch.value = '';
    editorOpen.value = false; confirmationOpen.value = false; fields.value = {}; errorKey.value = '';
    searchLoading.value = false;
    if (!selected.value) { loading.value = false; return; }
    loading.value = true;
    try {
        await Promise.all([
            loadRoles(signal), loadAssignments(signal),
            can('roles.view') ? request(url(props.endpoints.permissions), { signal }).then(response => { permissions.value = response.data; }) : Promise.resolve(),
        ]);
    } catch (error) { handleError(error); }
    finally { if (!signal.aborted) { loading.value = false; } }
}

async function loadContexts() {
    loading.value = true; errorKey.value = '';
    try {
        const response = await request(props.endpoints.contexts);
        if (disposed) { return; }
        contexts.value = response.data;
        selectedKey.value = contexts.value[0]?.scope_key ?? '';
        if (!selectedKey.value) { loading.value = false; }
    } catch (error) { handleError(error); loading.value = false; }
}

function showRole(role = null) {
    editingRole.value = role;
    Object.assign(roleForm, { code: role?.code ?? '', name_en: role?.name_en ?? '', name_lo: role?.name_lo ?? '', description: role?.description ?? '', active: role?.active ?? true, permission_keys: [...(role?.permission_keys ?? [])] });
    fields.value = {}; errorKey.value = ''; editorOpen.value = true;
}

function fieldError(name) { return fields.value[name]?.[0] ?? ''; }

async function saveRole() {
    if (!can('roles.manage') || readOnly.value || saving.value) { return; }
    fields.value = {};
    for (const name of ['code', 'name_en', 'name_lo']) {
        if (!roleForm[name].trim()) { fields.value[name] = ['rbacRequired']; }
    }
    if (Object.keys(fields.value).length) { return; }
    saving.value = true; errorKey.value = '';
    try {
        const data = { ...scopeData(), ...roleForm, permission_keys: [...roleForm.permission_keys] };
        if (editingRole.value) { delete data.code; }
        await request(editingRole.value ? `${props.endpoints.roles}/${editingRole.value.id}` : props.endpoints.roles, { method: editingRole.value ? 'PATCH' : 'POST', body: data });
        notify({ type: 'success', messageKey: 'rbacRoleSaved' });
        await loadScope();
    } catch (error) { handleError(error); }
    finally { saving.value = false; }
}

async function searchCandidates() {
    searchLoading.value = true; errorKey.value = '';
    const signal = scopeController?.signal;
    try {
        const [accountResponse, roleResponse] = await Promise.all([
            request(url(props.endpoints.users, { q: userSearch.value, per_page: 50 }), { signal }),
            request(url(props.endpoints.roles, { q: roleSearch.value, per_page: 50 }), { signal }),
        ]);
        users.value = accountResponse.data; roleOptions.value = roleResponse.data;
    } catch (error) { handleError(error); }
    finally { if (!signal?.aborted) { searchLoading.value = false; } }
}

function prepareAssignment() {
    const user = users.value.find(user => user.id === Number(targetUser.value));
    const role = roleOptions.value.find(role => role.id === Number(targetRole.value));
    if (!user || !role) { errorKey.value = 'rbacSelectAccountRole'; return; }
    pending.value = { kind: 'assign', user_id: user.id, role_id: role.id, account: `${user.name} (${user.email})`, role };
    errorKey.value = '';
    confirmationOpen.value = true;
}

function prepareRevoke(assignment) {
    pending.value = { kind: 'revoke', id: assignment.id, account: `${assignment.user.name} (${assignment.user.email})`, role: assignment.role };
    errorKey.value = '';
    confirmationOpen.value = true;
}

async function confirmAction() {
    if (!pending.value || saving.value) { return; }
    saving.value = true; errorKey.value = '';
    try {
        if (pending.value.kind === 'assign') {
            await request(props.endpoints.assignments, { method: 'POST', body: { ...scopeData(), user_id: pending.value.user_id, role_id: pending.value.role_id, confirm_global: selected.value.scope_type === 'global' } });
        } else {
            await request(`${props.endpoints.assignments}/${pending.value.id}`, { method: 'DELETE', body: scopeData() });
        }
        confirmationOpen.value = false;
        notify({ type: 'success', messageKey: 'rbacAssignmentSaved' });
        await loadScope();
    } catch (error) { confirmationOpen.value = false; handleError(error); }
    finally { saving.value = false; }
}

async function changeRolePage() {
    const signal = scopeController?.signal;
    loading.value = true;
    try { await loadRoles(signal); } catch (error) { handleError(error); }
    finally { if (!signal?.aborted) { loading.value = false; } }
}
async function changeAssignmentPage() {
    const signal = scopeController?.signal;
    loading.value = true;
    try { await loadAssignments(signal); } catch (error) { handleError(error); }
    finally { if (!signal?.aborted) { loading.value = false; } }
}

watch(selectedKey, loadScope);
onMounted(loadContexts);
onBeforeUnmount(() => { disposed = true; scopeController?.abort(); });
</script>

<template>
    <section class="flex flex-col gap-6" aria-labelledby="rbac-heading">
        <div><h2 id="rbac-heading" class="text-lg font-semibold">{{ t('rbacTitle') }}</h2><p class="mt-2 text-sm leading-7 text-slate-600">{{ t('rbacInheritance') }}</p></div>
        <p v-if="errorKey" role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ t(errorKey) }}</p>
        <p v-if="loading && !contexts.length" role="status">{{ t('uiLoading') }}</p>
        <p v-else-if="!contexts.length" class="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-600">{{ t('rbacNoAccess') }}</p>
        <template v-if="contexts.length">
            <div class="rounded-xl border border-slate-200 bg-white p-5"><SelectInput v-model="selectedKey" :label="t('rbacScope')" :options="scopeOptions" :disabled="saving || confirmationOpen" /><p class="mt-3 text-xs leading-6 text-slate-500">{{ t(selected?.scope_type === 'global' ? 'rbacGlobalWarning' : 'rbacScopeNotice') }}</p></div>

            <section v-if="can('roles.view')" class="flex flex-col gap-4" aria-labelledby="roles-heading">
                <div class="flex flex-wrap items-center justify-between gap-3"><h3 id="roles-heading" class="font-semibold">{{ t('rbacRoles') }}</h3><UiButton v-if="can('roles.manage')" :disabled="loading || saving" @click="showRole()">{{ t('rbacCreateRole') }}</UiButton></div>
                <DataTable v-model:page="rolePage" :rows="roleRows" :columns="roleColumns" :caption="t('rbacRoles')" :loading="loading" server-side :total="roleTotal" :per-page="10" @change="changeRolePage">
                    <template #cell-actions="{ row }"><UiButton variant="secondary" :disabled="saving" @click="showRole(row)">{{ t(row.editable ? 'rbacEdit' : 'rbacDetails') }}</UiButton></template>
                </DataTable>
            </section>

            <form v-if="editorOpen" novalidate class="flex flex-col gap-5 rounded-xl border border-slate-200 bg-white p-6" @submit.prevent="saveRole">
                <h3 class="font-semibold">{{ t(editingRole ? 'rbacRoleDetails' : 'rbacCreateRole') }}</h3>
                <p v-if="readOnly || securityReadOnly" class="text-sm text-slate-500">{{ t(readOnly ? 'rbacInheritedReadOnly' : 'rbacSelfRoleReadOnly') }}</p>
                <div class="grid gap-5 sm:grid-cols-2">
                    <TextInput v-model="roleForm.code" name="role-code" :label="t('rbacCode')" :helper="t('rbacCodeHelp')" :readonly="!!editingRole || readOnly" :error-key="fieldError('code')" required maxlength="64" />
                    <TextInput v-model="roleForm.name_en" name="role-name-en" :label="t('rbacNameEn')" :readonly="readOnly" :error-key="fieldError('name_en')" required lang="en" maxlength="255" />
                    <TextInput v-model="roleForm.name_lo" name="role-name-lo" :label="t('rbacNameLo')" :readonly="readOnly" :error-key="fieldError('name_lo')" required lang="lo" maxlength="255" />
                    <CheckboxInput v-model="roleForm.active" :label="t('rbacActive')" :disabled="securityReadOnly" />
                </div>
                <TextareaInput v-model="roleForm.description" :label="t('rbacDescription')" :readonly="readOnly" maxlength="2000" />
                <fieldset v-for="[area, group] in permissionGroups" :key="area" class="rounded-lg border border-slate-200 p-4" :disabled="securityReadOnly">
                    <legend class="px-2 text-sm font-semibold">{{ t(`rbacArea-${area}`) }}</legend>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label v-for="permission in group" :key="permission.key" class="flex items-start gap-3 text-sm">
                            <input v-model="roleForm.permission_keys" type="checkbox" :value="permission.key" :disabled="securityReadOnly || !permission.available || !permission.delegatable" class="mt-1 size-4 shrink-0 accent-teal-700" />
                            <span>{{ label(permission) }}<span class="block text-xs text-slate-500">{{ permission.key }} {{ !permission.available ? `· ${t('planned')}` : '' }}</span></span>
                        </label>
                    </div>
                </fieldset>
                <p v-if="fieldError('permission_keys')" role="alert" class="text-sm text-red-700">{{ t(fieldError('permission_keys')) }}</p>
                <div class="flex flex-wrap gap-3"><UiButton v-if="can('roles.manage') && !readOnly" type="submit" :loading="saving">{{ t('rbacSaveRole') }}</UiButton><UiButton variant="secondary" :disabled="saving" @click="editorOpen = false">{{ t('uiCancel') }}</UiButton></div>
            </form>

            <section v-if="can('assignments.view')" class="flex flex-col gap-4" aria-labelledby="assignments-heading">
                <h3 id="assignments-heading" class="font-semibold">{{ t('rbacAssignments') }}</h3>
                <DataTable v-model:page="assignmentPage" :rows="assignmentRows" :columns="assignmentColumns" :caption="t('rbacAssignments')" :loading="loading" server-side :total="assignmentTotal" :per-page="10" @change="changeAssignmentPage">
                    <template #cell-actions="{ row }"><UiButton v-if="can('assignments.manage') && row.active && row.revocable" variant="danger" :disabled="saving" @click="prepareRevoke(row)">{{ t('rbacRevoke') }}</UiButton></template>
                </DataTable>
            </section>

            <section v-if="can('assignments.manage') && can('users.view') && can('roles.view')" class="flex flex-col gap-5 rounded-xl border border-slate-200 bg-white p-6" aria-labelledby="grant-heading">
                <h3 id="grant-heading" class="font-semibold">{{ t('rbacAssignRole') }}</h3>
                <p class="text-sm leading-7 text-slate-500">{{ t('rbacEligibleNotice') }}</p>
                <div class="grid gap-5 sm:grid-cols-2"><TextInput v-model="userSearch" :label="t('rbacAccountSearch')" /><TextInput v-model="roleSearch" :label="t('rbacRoleSearch')" /></div>
                <div><UiButton variant="secondary" :loading="searchLoading" :disabled="saving || loading" @click="searchCandidates">{{ t('rbacFindCandidates') }}</UiButton></div>
                <div class="grid gap-5 sm:grid-cols-2"><SelectInput v-model="targetUser" :label="t('rbacAccount')" :options="candidateUsers" :disabled="saving || searchLoading" /><SelectInput v-model="targetRole" :label="t('rbacRole')" :options="candidateRoles" :disabled="saving || searchLoading" /></div>
                <p v-if="!searchLoading && users.length === 0" class="text-xs text-slate-500">{{ t('rbacNoCandidates') }}</p>
                <div><UiButton :disabled="saving || loading || !targetUser || !targetRole" @click="prepareAssignment">{{ t('rbacAssignRole') }}</UiButton></div>
            </section>
        </template>
        <ConfirmDialog v-model="confirmationOpen" :danger="pending?.kind === 'revoke'" :title="t(pending?.kind === 'assign' ? 'rbacAssignRole' : 'rbacRevoke')" :message="confirmationMessage" :loading="saving" @confirm="confirmAction" />
        <ToastNotifications :items="items" @dismiss="dismiss" />
    </section>
</template>
