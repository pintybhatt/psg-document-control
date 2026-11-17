<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import UiButton from '../components/ui/UiButton.vue';
import IconButton from '../components/ui/IconButton.vue';
import TextInput from '../components/ui/TextInput.vue';
import TextareaInput from '../components/ui/TextareaInput.vue';
import SelectInput from '../components/ui/SelectInput.vue';
import CheckboxInput from '../components/ui/CheckboxInput.vue';
import DataTable from '../components/ui/DataTable.vue';
import StatusBadge from '../components/ui/StatusBadge.vue';
import ConfirmDialog from '../components/ui/SweetConfirmDialog.vue';
import FormModal from '../components/ui/FormModal.vue';
import BusyOverlay from '../components/ui/BusyOverlay.vue';
import ConfidentialityAccess from '../components/ConfidentialityAccess.vue';
import MembershipManagement from '../components/MembershipManagement.vue';
import UserManagement from '../components/UserManagement.vue';
import DocumentMasterDataManagement from '../components/DocumentMasterDataManagement.vue';
import OrganizationManagement from '../components/OrganizationManagement.vue';
import AdministrationHistory from '../components/AdministrationHistory.vue';
import { useWorkspace } from '../utilities/workspace.js';
import NumberingManagement from '../components/NumberingManagement.vue';
import TemplateCatalogue from '../components/TemplateCatalogue.vue';
import ApprovalWorkflows from '../components/ApprovalWorkflows.vue';
import { showSuccess } from '../utilities/alerts.js';
import { useLocalization } from '../localization/index.js';
import { createJsonClient } from '../utilities/jsonClient.js';
import { Icon } from '@iconify/vue';
import buildingIcon from '@iconify-icons/flowbite/building-outline';
import usersGroupIcon from '@iconify-icons/flowbite/users-group-outline';
import userSettingsIcon from '@iconify-icons/flowbite/user-settings-outline';
import filePenIcon from '@iconify-icons/flowbite/file-pen-outline';
import listIcon from '@iconify-icons/flowbite/list-outline';
import lockIcon from '@iconify-icons/flowbite/lock-outline';
import bookIcon from '@iconify-icons/flowbite/book-open-outline';
import clipboardCheckIcon from '@iconify-icons/flowbite/clipboard-check-outline';
import clockArrowIcon from '@iconify-icons/flowbite/clock-arrow-outline';
import shieldCheckIcon from '@iconify-icons/flowbite/shield-check-outline';

const props = defineProps({ endpoints: { type: Object, required: true }, csrfToken: { type: String, required: true } });
const { t, language } = useLocalization();
const request = createJsonClient(props.csrfToken);
const workspace = useWorkspace();
const contexts = ref([]);
const canManageOrganization = ref(false);
const selectedKey = ref('');
const section = ref('');
const group = ref('overview');
const selected = computed(() => contexts.value.find(scope => scope.scope_key === selectedKey.value));
const can = permission => selected.value?.permissions.includes(permission) ?? false;
const canManageUsers = computed(() => canManageOrganization.value && contexts.value.some(scope => scope.scope_type === 'global' && scope.permissions.includes('users.manage')));
const sections = computed(() => [
    { key: 'organization', label: 'orgTitle', icon: buildingIcon, available: !!props.endpoints.organization && canManageOrganization.value && selected.value?.scope_type === 'global' },
    { key: 'users', label: 'userManagementTitle', icon: userSettingsIcon, available: !!props.endpoints.userManagement && canManageUsers.value },
    { key: 'people', label: 'adminMemberships', icon: usersGroupIcon, available: can('memberships.view') },
    { key: 'roles', label: 'adminRoles', icon: shieldCheckIcon, available: can('roles.view') || can('assignments.view') },
    { key: 'documents', label: 'master-types', icon: filePenIcon, available: !!props.endpoints.masterData && selected.value?.scope_type !== 'global' && can('master-data.view') },
    { key: 'categories', label: 'master-categories', icon: filePenIcon, available: !!props.endpoints.masterData && selected.value?.scope_type !== 'global' && can('master-data.view') },
    { key: 'registers', label: 'master-registers', icon: filePenIcon, available: !!props.endpoints.masterData && selected.value?.scope_type !== 'global' && can('master-data.view') },
    { key: 'numbering', label: 'adminNumbering', icon: listIcon, available: !!props.endpoints.numbering && selected.value?.scope_type !== 'global' && can('numbering.view') },
    { key: 'approvalWorkflows', label: 'workflowTitle', icon: clipboardCheckIcon, available: !!props.endpoints.approvalWorkflows && selected.value?.scope_type !== 'global' && can('master-data.view') },
    { key: 'templates', label: 'templateTitle', icon: bookIcon, available: !!props.endpoints.templates && selected.value?.scope_type === 'site' && selected.value?.can_access_templates === true && can('templates.view') },
    { key: 'confidentiality', label: 'confidentialityTitle', icon: lockIcon, available: !!props.endpoints.confidentiality && selected.value?.can_manage_document_security === true },
    { key: 'history', label: 'adminHistory', icon: clockArrowIcon, available: !!props.endpoints.history && can('security.audit.view') },
].filter(item => item.available));
const sectionGroup = key => ['users', 'people', 'roles'].includes(key) ? 'access'
    : ['documents', 'categories', 'registers', 'numbering', 'approvalWorkflows', 'templates'].includes(key) ? 'documents'
        : key === 'confidentiality' ? 'security' : key;
const groups = computed(() => [
    { key: 'overview', label: 'adminOverview', icon: listIcon },
    { key: 'access', label: 'adminUsersAccess', icon: usersGroupIcon },
    { key: 'organization', label: 'adminOrganization', icon: buildingIcon },
    { key: 'documents', label: 'adminDocuments', icon: filePenIcon },
    { key: 'security', label: 'adminSecurity', icon: lockIcon },
    { key: 'history', label: 'adminHistory', icon: clockArrowIcon },
].filter(item => item.key === 'overview' || sections.value.some(section => sectionGroup(section.key) === item.key)));
const localSections = computed(() => sections.value.filter(item => sectionGroup(item.key) === group.value));
const masterKind = computed(() => ['categories', 'registers'].includes(section.value) ? section.value : 'types');
function openSection(key) { section.value = key; group.value = sectionGroup(key); }
function openGroup(key) {
    group.value = key;
    section.value = key === 'overview' ? '' : sections.value.find(item => sectionGroup(item.key) === key)?.key ?? '';
}
watch(sections, values => {
    if (section.value && !values.some(item => item.key === section.value)) { openGroup(groups.value.some(item => item.key === group.value) ? group.value : 'overview'); }
});

const loading = ref(true);
const saving = ref(false);
const errorKey = ref('');
const fields = ref({});
const roles = ref([]);
const permissions = ref([]);
const recommendations = ref({});
const recommendationPreview = ref(false);
const recommendationApplied = ref(false);
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
const assignOpen = ref(false);
const roleEditor = ref(null);
const scopePanel = ref(null);
const editingRole = ref(null);
const readOnly = computed(() => editingRole.value !== null && !editingRole.value.editable);
const securityReadOnly = computed(() => readOnly.value || editingRole.value?.security_editable === false);
const owningScope = computed(() => readOnly.value && editingRole.value?.scope_key !== selectedKey.value
    ? contexts.value.find(scope => scope.scope_key === editingRole.value?.scope_key && scope.permissions.includes('roles.view') && scope.permissions.includes('roles.manage')) : null);
const roleForm = reactive({ code: '', name_en: '', name_lo: '', description: '', active: true, permission_keys: [] });
const roleRecommendation = computed(() => Object.hasOwn(recommendations.value, roleForm.code) ? recommendations.value[roleForm.code] : null);
const recommendedPermissions = computed(() => (roleRecommendation.value?.permission_keys ?? []).map(key => permissions.value.find(permission => permission.key === key)
    ?? { key, name_en: key, name_lo: key, available: false, delegatable: false }));
const recommendationScopeAllowed = computed(() => roleRecommendation.value?.scope_types.includes(selected.value?.scope_type) ?? false);
const recommendationBlocked = computed(() => recommendedPermissions.value.some(permission => !permission.available || !permission.delegatable));
const canPreviewRecommendation = computed(() => can('roles.view') && recommendationScopeAllowed.value && !saving.value && !loading.value);
const canApplyRecommendation = computed(() => canPreviewRecommendation.value && can('roles.manage') && !securityReadOnly.value);
watch(() => roleForm.code, () => { recommendationPreview.value = false; recommendationApplied.value = false; });
const confirmationOpen = ref(false);
const pending = ref(null);
const searchLoading = ref(false);
let scopeController;
let disposed = false;

const label = record => record?.[language.value === 'lo' ? 'name_lo' : 'name_en'] ?? '';
const scopeLabel = scope => scope.scope_type === 'global' ? t('rbacGlobal')
    : `${t(scope.scope_type === 'company' ? 'company' : 'site')}: ${label(scope.company)}${scope.site ? ` / ${label(scope.site)}` : ''}`;
const scopeOptions = computed(() => contexts.value.map(scope => ({ value: scope.scope_key, label: scopeLabel(scope) })));
const roleRows = computed(() => roles.value.map(role => ({ ...role, display_name: label(role), display_scope: roleScopeLabel(role), status: t(role.active ? 'rbacActive' : 'rbacInactive') })));
const roleColumns = computed(() => [
    { key: 'display_name', label: t('rbacRole') }, { key: 'display_scope', label: t('rbacScope') },
    { key: 'status', label: t('uiStatus') }, { key: 'actions', label: t('rbacActions') },
]);
const assignmentRows = computed(() => assignments.value.map(assignment => ({ ...assignment, user_name: assignment.user.name, role_name: label(assignment.role), status: t(assignment.active ? 'rbacActive' : 'rbacInactive') })));
const assignmentColumns = computed(() => [
    { key: 'user_name', label: t('rbacAccount') }, { key: 'role_name', label: t('rbacRole') },
    { key: 'scope_key', label: t('rbacScope') }, { key: 'status', label: t('uiStatus') }, { key: 'actions', label: t('rbacActions') },
]);
const businessGroups = ['workspace', 'correspondence', 'controlled', 'document-tools', 'reports', 'administration', 'security'];
const permissionGroups = computed(() => businessGroups.map(key => ({ key, permissions: permissions.value.filter(permission => permissionGroup(permission) === key) })).filter(group => group.permissions.length));
const advancedPermissions = computed(() => permissions.value.filter(permission => permissionGroup(permission) === null));
const workspaceNotice = computed(() => {
    if (roleForm.code === 'SECURITY_ADMINISTRATOR') { return 'rbacWorkspaceAdministrator'; }
    if (!roleForm.active) { return 'rbacWorkspaceInactive'; }
    if (missingWorkspacePermissions(roleForm)) { return 'rbacWorkspaceMissing'; }
    if (!roleForm.permission_keys.every(key => permissions.value.some(permission => permission.key === key && permission.available && permission.delegatable))) { return 'rbacWorkspaceUnavailable'; }
    return 'rbacWorkspaceConfigured';
});

function permissionGroup(permission) {
    if (!permission.available) { return null; }
    const key = permission.key;
    if (['organization.access', 'documents.metadata.view', 'documents.content.preview', 'documents.attachments.download'].includes(key)) { return 'workspace'; }
    if (/^(incoming|outgoing)\./.test(key)) { return 'correspondence'; }
    if (key.startsWith('controlled.')) { return 'controlled'; }
    if (['documents.attachments.manage', 'documents.custody.manage', 'numbering.issue', 'storage-locations.manage'].includes(key)) { return 'document-tools'; }
    if (key === 'documents.audit.view' || key.startsWith('reports.')) { return 'reports'; }
    if (/^(users|roles|assignments|memberships|master-data|numbering)\./.test(key)) { return 'administration'; }
    if (key === 'security.audit.view' || key.startsWith('clearances.') || key.startsWith('document-access.')) { return 'security'; }
    return null;
}

function permissionLabel(permission) {
    if (permission.key === 'organization.access') { return t('rbacWorkspaceAccessLabel'); }
    if (permission.key === 'documents.metadata.view') { return t('rbacWorkspaceDocumentsLabel'); }
    const implementedOrLegacy = permission.available || ['documents.view', 'documents.register', 'documents.control'].includes(permission.key);
    return implementedOrLegacy ? label(permission).replace(/\s*\((planned|ຢູ່ໃນແຜນ)\)/g, '') : label(permission);
}

function missingWorkspacePermissions(role) {
    return ['organization.access', 'documents.metadata.view'].some(key => !role.permission_keys.includes(key) || !permissions.value.some(permission => permission.key === key && permission.available));
}

function roleScopeLabel(role) {
    const owner = contexts.value.find(scope => scope.scope_key === role?.scope_key);
    if (owner) { return scopeLabel(owner); }
    if (role?.scope_key === 'global') { return t('rbacGlobal'); }
    return role?.scope_key?.startsWith('company:') ? `${t('company')}: ${label(selected.value?.company)}` : t('rbacSiteRoleScope');
}

function selectedPermissions(group) { return group.filter(permission => roleForm.permission_keys.includes(permission.key)).length; }
const candidateUsers = computed(() => [{ value: '', label: t('uiChoose') }, ...users.value.map(user => ({ value: user.id, label: `${user.name} (${user.email})` }))]);
const candidateRoles = computed(() => [{ value: '', label: t('uiChoose') }, ...roleOptions.value.filter(role => role.assignable !== false && role.active && role.permission_keys.every(key => permissions.value.some(permission => permission.key === key && permission.available && permission.delegatable))).map(role => ({ value: role.id, label: `${label(role)} (${role.code})` }))]);
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
        canManageOrganization.value = false;
        scopeController?.abort();
        loading.value = false; searchLoading.value = false;
        roles.value = []; assignments.value = []; users.value = []; permissions.value = []; roleOptions.value = [];
        recommendations.value = {}; recommendationPreview.value = false; recommendationApplied.value = false;
        if (selected.value) { selected.value.permissions = []; selected.value.can_manage_document_security = false; }
        editorOpen.value = false; assignOpen.value = false;
        confirmationOpen.value = false;
    }
}

function handleTemplateError(error) {
    if (error.status === 403) {
        if (selected.value) { selected.value.can_access_templates = false; }
        errorKey.value = 'adminTemplateScopeRestricted';
        return;
    }
    handleError(error);
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
    recommendations.value = {}; recommendationPreview.value = false; recommendationApplied.value = false;
    roleTotal.value = 0; assignmentTotal.value = 0; rolePage.value = 1; assignmentPage.value = 1;
    targetUser.value = ''; targetRole.value = ''; userSearch.value = ''; roleSearch.value = '';
    editorOpen.value = false; assignOpen.value = false; confirmationOpen.value = false; fields.value = {}; errorKey.value = '';
    searchLoading.value = false;
    if (!selected.value) { loading.value = false; return; }
    loading.value = true;
    try {
        await Promise.all([
            loadRoles(signal), loadAssignments(signal),
            can('roles.view') ? request(url(props.endpoints.permissions), { signal }).then(response => { permissions.value = response.data; recommendations.value = response.recommendations ?? {}; }) : Promise.resolve(),
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
        canManageOrganization.value = response.can_manage_organization === true;
        selectWorkspaceScope();
        if (!selectedKey.value) { selectedKey.value = contexts.value[0]?.scope_key ?? ''; }
        if (!selectedKey.value) { loading.value = false; }
    } catch (error) { handleError(error); loading.value = false; }
}

async function showRole(role = null) {
    editingRole.value = role;
    recommendationPreview.value = false; recommendationApplied.value = false;
    Object.assign(roleForm, { code: role?.code ?? '', name_en: role?.name_en ?? '', name_lo: role?.name_lo ?? '', description: role?.description ?? '', active: role?.active ?? true, permission_keys: [...(role?.permission_keys ?? [])] });
    fields.value = {}; errorKey.value = ''; editorOpen.value = true;
    await nextTick();
    const target = roleEditor.value?.querySelector('input:not([readonly]):not(:disabled)') ?? roleEditor.value?.querySelector('h3');
    target?.focus();
}

function fieldError(name) { return fields.value[name]?.[0] ?? ''; }

function previewRecommendation() {
    if (canPreviewRecommendation.value) { recommendationPreview.value = true; }
}

function applyRecommendation() {
    if (!recommendationPreview.value || !canApplyRecommendation.value || recommendationBlocked.value) { return; }
    roleForm.permission_keys = [...new Set([...roleForm.permission_keys, ...roleRecommendation.value.permission_keys])];
    recommendationPreview.value = false; recommendationApplied.value = true;
}

async function switchToOwningScope() {
    if (!owningScope.value || saving.value || loading.value || confirmationOpen.value) { return; }
    selectedKey.value = owningScope.value.scope_key;
    await nextTick();
    scopePanel.value?.querySelector('select')?.focus();
}

async function focusInvalidRoleField() {
    await nextTick();
    roleEditor.value?.querySelector('[aria-invalid="true"]')?.focus();
}

async function saveRole() {
    if (!can('roles.manage') || readOnly.value || saving.value) { return; }
    fields.value = {};
    for (const name of ['code', 'name_en', 'name_lo']) {
        if (!roleForm[name].trim()) { fields.value[name] = ['rbacRequired']; }
    }
    if (Object.keys(fields.value).length) { await focusInvalidRoleField(); return; }
    saving.value = true; errorKey.value = '';
    try {
        const data = { ...scopeData(), ...roleForm, permission_keys: [...roleForm.permission_keys] };
        if (editingRole.value) { delete data.code; }
        await request(editingRole.value ? `${props.endpoints.roles}/${editingRole.value.id}` : props.endpoints.roles, { method: editingRole.value ? 'PATCH' : 'POST', body: data });
        showSuccess(t('uiSaveSuccess'), t('rbacRoleSaved'));
        await loadScope();
    } catch (error) { handleError(error); await focusInvalidRoleField(); }
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
        if (pending.value.kind === 'assign') { assignOpen.value = false; }
        showSuccess(t('uiSaveSuccess'), t('rbacAssignmentSaved'));
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

async function organizationSaved() {
    showSuccess(t('uiSaveSuccess'), t('orgSaved'));
    try {
        const response = await request(props.endpoints.contexts);
        if (disposed) { return; }
        contexts.value = response.data;
        canManageOrganization.value = response.can_manage_organization === true;
        await workspace?.refresh();
    } catch (error) { handleError(error); }
}

function selectWorkspaceScope() {
    const unit = workspace?.selected.value;
    if (section.value === 'organization' || !unit || saving.value || confirmationOpen.value || editorOpen.value || assignOpen.value) { return; }
    const match = contexts.value.find(scope => scope.scope_type === 'site' && scope.company_id === unit.company_id && scope.site_id === unit.site_id);
    if (match) { selectedKey.value = match.scope_key; }
}
watch(() => workspace?.selected.value, selectWorkspaceScope);
watch(selectedKey, loadScope);
onMounted(loadContexts);
onBeforeUnmount(() => { disposed = true; scopeController?.abort(); });
</script>

<template>
    <section class="flex flex-col gap-6" aria-labelledby="rbac-heading">
        <h2 id="rbac-heading" class="sr-only">{{ t('administration') }}</h2>

        <p v-if="errorKey && !assignOpen && !editorOpen" role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ t(errorKey) }}</p>
        <p v-if="loading && !contexts.length" role="status">{{ t('uiLoading') }}</p>
        <p v-else-if="!contexts.length" class="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-600">{{ t('rbacNoAccess') }}</p>
        <template v-if="contexts.length">
            <div ref="scopePanel" data-administration-scope class="rounded-xl border border-slate-200 bg-white p-4">
                <div class="grid items-center gap-3 sm:grid-cols-2">
                    <div><p class="text-sm font-semibold text-teal-800">{{ t('adminContext') }}</p><p v-if="selected" class="mt-1 font-semibold wrap-anywhere" aria-live="polite">{{ t('rbacCurrentScope', { scope: scopeLabel(selected) }) }}</p></div>
                    <SelectInput v-model="selectedKey" :label="t('rbacScope')" :options="scopeOptions" :disabled="saving || confirmationOpen" />
                </div>
                <p class="mt-3 text-sm leading-6" :class="selected?.scope_type === 'global' ? 'rounded-lg bg-amber-50 p-3 font-medium text-amber-900' : 'text-slate-600'">{{ t(selected?.scope_type === 'global' ? 'rbacGlobalWarning' : 'rbacScopeNotice') }}</p>
                <details class="mt-2 text-sm text-slate-600"><summary class="cursor-pointer font-medium">{{ t('rbacScopeGuide') }}</summary>
                    <p class="mt-2 leading-6">{{ t('rbacInheritance') }}</p>
                    <dl class="mt-2 grid gap-2 sm:grid-cols-3"><div><dt class="font-semibold">{{ t('rbacGlobal') }}</dt><dd>{{ t('rbacScopeGlobalHelp') }}</dd></div><div><dt class="font-semibold">{{ t('company') }}</dt><dd>{{ t('rbacScopeCompanyHelp') }}</dd></div><div><dt class="font-semibold">{{ t('site') }}</dt><dd>{{ t('rbacScopeSiteHelp') }}</dd></div></dl>
                </details>
            </div>

            <nav :aria-label="t('administration')" data-admin-tabs class="-mx-1 overflow-x-auto px-1 pb-1 [scrollbar-width:thin]">
                <div class="flex w-max gap-1 rounded-2xl border border-slate-200/80 bg-white/70 p-1.5 shadow-sm shadow-slate-900/5 backdrop-blur-md sm:w-auto sm:flex-wrap">
                    <button v-for="item in groups" :key="item.key" type="button" :data-admin-tab="item.key" :aria-pressed="group === item.key" class="group inline-flex shrink-0 items-center gap-2 rounded-xl py-2 pr-3.5 pl-2 text-sm font-medium whitespace-nowrap transition-all duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-600 motion-reduce:transition-none" :class="group === item.key ? 'bg-linear-to-br from-teal-600 to-sky-700 text-white shadow-md shadow-teal-700/25' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'" @click="openGroup(item.key)">
                        <span aria-hidden="true" class="inline-flex size-7 items-center justify-center rounded-lg transition-colors" :class="group === item.key ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-500 group-hover:bg-white group-hover:text-teal-700'"><Icon :icon="item.icon" class="size-4" /></span>
                        <span>{{ t(item.label) }}</span>
                    </button>
                </div>
            </nav>
            <section v-if="group === 'overview'" aria-labelledby="admin-overview-heading" class="flex flex-col gap-4">
                <div><h3 id="admin-overview-heading" class="text-lg font-semibold">{{ t('adminOverview') }}</h3><p class="mt-1 text-sm text-slate-600">{{ t('adminOverviewHelp') }}</p></div>
                <p v-if="canManageOrganization && selected?.scope_type !== 'global'" class="text-sm text-slate-600">{{ t('adminGlobalHelp') }}</p>
                <p v-if="!sections.length" role="status" class="rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-600">{{ t('adminNoTasks') }}</p>
                <p v-if="selected?.scope_type === 'site' && can('templates.view') && selected?.can_access_templates === false" role="status" class="text-sm text-slate-600">{{ t('adminTemplateScopeRestricted') }}</p>
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <button v-for="item in sections" :key="item.key" type="button" :data-admin-shortcut="item.key" class="flex items-start gap-3 rounded-xl border border-slate-200 bg-white p-4 text-left transition hover:border-teal-500 hover:bg-teal-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-600" @click="openSection(item.key)">
                        <Icon :icon="item.icon" class="mt-1 size-5 shrink-0 text-teal-700" aria-hidden="true" />
                        <span><span class="block font-semibold">{{ t(item.label) }}</span><span class="mt-1 block text-sm text-slate-600">{{ t(`adminHelp-${sectionGroup(item.key)}`) }}</span></span>
                    </button>
                </div>
            </section>
            <template v-if="group !== 'overview'">
                <p v-if="group === 'access'" class="text-sm leading-6 text-slate-600">{{ t('adminAccessHelp') }}</p>
                <p v-if="group === 'organization'" class="text-sm text-slate-600">{{ t('adminGlobalHelp') }}</p>
                <nav v-if="localSections.length > 1" :aria-label="t('adminTaskNavigation')" class="flex flex-wrap gap-2 border-b border-slate-200 pb-3">
                    <UiButton v-for="item in localSections" :key="item.key" :data-admin-task="item.key" :variant="section === item.key ? 'primary' : 'secondary'" :aria-pressed="section === item.key" @click="openSection(item.key)">{{ t(item.label) }}</UiButton>
                </nav>
            </template>
            <OrganizationManagement v-if="section === 'organization' && canManageOrganization && selected?.scope_type === 'global'" :endpoint="endpoints.organization" :csrf-token="csrfToken" @saved="organizationSaved" @unauthorized="handleError" />
            <ConfidentialityAccess v-if="section === 'confidentiality' && selected?.can_manage_document_security" :key="selectedKey" :scope="selected" :endpoint="endpoints.confidentiality" :csrf-token="csrfToken" @unauthorized="handleError" />
            <AdministrationHistory v-if="section === 'history' && selected && can('security.audit.view')" :scope="selected" :endpoint="endpoints.history" :can-manage-organization="canManageOrganization" :csrf-token="csrfToken" @unauthorized="handleError" />
            <MembershipManagement v-show="section === 'people'" v-if="selected && can('memberships.view')" :scope="selected" :endpoints="endpoints" :csrf-token="csrfToken" @unauthorized="handleError" />
            <UserManagement v-if="section === 'users' && canManageUsers" :endpoint="endpoints.userManagement" :access-endpoints="{ units: endpoints.membershipUnits, roles: endpoints.roles, permissions: endpoints.permissions }" :current-user-id="endpoints.currentUserId" :csrf-token="csrfToken" />
            <DocumentMasterDataManagement v-show="['documents', 'categories', 'registers'].includes(section)" :kind="masterKind" v-if="endpoints.masterData && selected && selected.scope_type !== 'global' && can('master-data.view')" :scope="selected" :endpoints="endpoints.masterData" :csrf-token="csrfToken" @unauthorized="handleError" />
            <NumberingManagement v-show="section === 'numbering'" v-if="endpoints.numbering && selected && selected.scope_type !== 'global' && can('numbering.view')" :scope="selected" :endpoint="endpoints.numbering" :csrf-token="csrfToken" @unauthorized="handleError" />
            <TemplateCatalogue v-if="section === 'templates' && selected?.scope_type === 'site' && selected?.can_access_templates === true && can('templates.view')" :key="selectedKey" :scope="selected" :endpoint="endpoints.templates" :csrf-token="csrfToken" @unauthorized="handleTemplateError" />
            <ApprovalWorkflows v-if="section === 'approvalWorkflows' && selected && selected.scope_type !== 'global' && can('master-data.view')" :scope="selected" :endpoint="endpoints.approvalWorkflows" :csrf-token="csrfToken" @unauthorized="handleError" />

            <section v-show="section === 'roles'" v-if="can('roles.view')" class="flex flex-col gap-4" aria-labelledby="roles-heading">
                <div class="flex flex-wrap items-center justify-between gap-3"><h3 id="roles-heading" class="font-semibold">{{ t('rbacRoles') }}</h3><UiButton v-if="can('roles.manage')" :disabled="loading || saving" @click="showRole()">{{ t('rbacCreateRole') }}</UiButton></div>
                <p v-if="selected" data-roles-scope class="rounded-lg border border-teal-200 bg-teal-50 px-4 py-3 text-sm font-semibold text-teal-900">{{ t('rbacRolesScope', { scope: scopeLabel(selected) }) }}</p>
                <DataTable v-model:page="rolePage" :rows="roleRows" :columns="roleColumns" :caption="t('rbacRoles')" :loading="loading" server-side :total="roleTotal" :per-page="10" @change="changeRolePage">
                    <template #cell-status="{ row }"><StatusBadge :status="row.active ? 'effective' : 'inactive'" :label="row.status" /></template>
                    <template #cell-display_name="{ row }"><span class="font-semibold">{{ row.display_name }}</span><span v-if="row.code !== 'SECURITY_ADMINISTRATOR' && missingWorkspacePermissions(row)" class="block text-xs text-amber-800">{{ t('rbacWorkspaceSetupNeeded') }}</span></template>
                    <template #cell-actions="{ row }"><IconButton :action="row.editable ? 'edit' : 'view'" :label="t(row.editable ? 'rbacEdit' : 'rbacDetails')" :disabled="saving" @click="showRole(row)" /></template>
                </DataTable>
            </section>

            <FormModal v-if="editorOpen && section === 'roles'" size="xl" :title="t(editingRole ? 'rbacRoleDetails' : 'rbacCreateRole')" :subtitle="editingRole ? `${label(editingRole)} · ${t('rbacRoleOwner', { scope: roleScopeLabel(editingRole) })}` : ''" :busy="saving" @close="editorOpen = false">
            <form ref="roleEditor" novalidate class="flex flex-col gap-5" @submit.prevent="saveRole">
                <p v-if="errorKey" role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ t(errorKey) }}</p>
                <p v-if="readOnly || securityReadOnly" class="text-sm text-slate-500">{{ t(readOnly ? 'rbacInheritedReadOnly' : 'rbacSelfRoleReadOnly', { scope: roleScopeLabel(editingRole) }) }}</p>
                <div v-if="readOnly && editingRole?.scope_key !== selectedKey" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    <UiButton v-if="owningScope" :disabled="loading || saving || confirmationOpen" @click="switchToOwningScope">{{ t('rbacSwitchOwnerScope', { scope: scopeLabel(owningScope) }) }}</UiButton>
                    <p v-else>{{ t('rbacOwnerScopeNoEdit') }}</p>
                </div>
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div class="sm:col-span-2"><TextInput v-model="roleForm.code" name="role-code" :label="t('rbacCode')" :helper="t('rbacCodeHelp')" :readonly="!!editingRole || readOnly" :error-key="fieldError('code')" required maxlength="64" /></div>
                    <TextInput v-model="roleForm.name_en" name="role-name-en" :label="t('rbacNameEn')" :readonly="readOnly" :error-key="fieldError('name_en')" required lang="en" maxlength="255" />
                    <TextInput v-model="roleForm.name_lo" name="role-name-lo" :label="t('rbacNameLo')" :readonly="readOnly" :error-key="fieldError('name_lo')" required lang="lo" maxlength="255" />
                    <div class="sm:col-span-2"><TextareaInput v-model="roleForm.description" :label="t('rbacDescription')" :readonly="readOnly" maxlength="2000" /></div>
                    <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 sm:col-span-2"><CheckboxInput v-model="roleForm.active" :label="t('rbacActive')" :disabled="securityReadOnly" /></div>
                </div>
                <p v-if="!editingRole" class="text-sm text-slate-600">{{ t('rbacStandardRoleHelp') }}</p>
                <section v-if="roleRecommendation" class="flex flex-col gap-3 rounded-lg border border-teal-200 bg-teal-50 p-4" :aria-label="t('rbacRecommendedPermissions')">
                    <h4 class="font-semibold">{{ t('rbacRecommendedPermissions') }}</h4>
                    <p class="text-sm">{{ t(`rbacRecommendation-${roleForm.code}`) }}</p>
                    <p class="text-sm text-slate-600">{{ t('rbacRecommendationHelp') }}</p>
                    <p v-if="!recommendationScopeAllowed" class="text-sm text-amber-900">{{ t('rbacRecommendationGlobalOnly') }}</p>
                    <p v-if="selected?.scope_type === 'global'" class="text-sm font-medium text-amber-900">{{ t('rbacGlobalWarning') }}</p>
                    <p v-if="!canApplyRecommendation && recommendationScopeAllowed" class="text-sm text-slate-600">{{ t('rbacRecommendationReadOnly') }}</p>
                    <div><UiButton :disabled="!canPreviewRecommendation" @click="previewRecommendation">{{ t(canApplyRecommendation ? 'rbacApplyRecommended' : 'rbacPreviewRecommended') }}</UiButton></div>
                    <div v-if="recommendationPreview" data-recommendation-preview class="flex flex-col gap-3">
                        <h5 class="text-sm font-semibold">{{ t('rbacRecommendationPreview') }}</h5>
                        <ul class="grid max-h-72 gap-2 overflow-y-auto text-sm sm:grid-cols-2"><li v-for="permission in recommendedPermissions" :key="permission.key">
                            {{ permissionLabel(permission) }} · {{ t(!permission.available ? 'rbacPermissionUnavailable' : !permission.delegatable ? 'rbacPermissionNotDelegatable' : roleForm.permission_keys.includes(permission.key) ? 'rbacPermissionSelected' : 'rbacRecommendationWillAdd') }}
                        </li></ul>
                        <p v-if="recommendationBlocked" role="status" class="text-sm text-amber-900">{{ t('rbacRecommendationBlocked') }}</p>
                        <div class="flex flex-wrap gap-3"><UiButton :disabled="!canApplyRecommendation || recommendationBlocked" @click="applyRecommendation">{{ t('rbacUseRecommended') }}</UiButton><UiButton variant="secondary" :disabled="saving" @click="recommendationPreview = false">{{ t('uiCancel') }}</UiButton></div>
                    </div>
                    <p v-if="recommendationApplied" role="status" class="text-sm text-teal-900">{{ t('rbacRecommendationApplied') }}</p>
                </section>
                <p class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm leading-7" role="status">{{ t(workspaceNotice) }}</p>
                <p class="text-sm text-slate-600">{{ t('rbacPermissionHelp') }}</p>
                <details v-for="group in permissionGroups" :key="group.key" :open="group.key === 'workspace'" :data-permission-group="group.key" class="rounded-lg border border-slate-200 p-4">
                    <summary class="cursor-pointer text-sm font-semibold">{{ t(`rbacGroup-${group.key}`) }} <span class="font-normal text-slate-500">· {{ t('rbacPermissionCount', { selected: selectedPermissions(group.permissions), total: group.permissions.length }) }}</span></summary>
                    <fieldset class="mt-4 grid gap-4 sm:grid-cols-2" :disabled="securityReadOnly">
                        <legend class="sr-only">{{ t(`rbacGroup-${group.key}`) }}</legend>
                        <label v-for="permission in group.permissions" :key="permission.key" class="flex items-start gap-3 text-sm">
                            <input v-model="roleForm.permission_keys" type="checkbox" :value="permission.key" :disabled="securityReadOnly || !permission.delegatable" class="mt-1 size-4 shrink-0 accent-teal-700" />
                            <span class="wrap-anywhere">{{ permissionLabel(permission) }}<span v-if="!permission.delegatable" class="block text-xs text-slate-500">{{ t('rbacPermissionNotDelegatable') }}</span></span>
                        </label>
                    </fieldset>
                </details>
                <details data-permission-advanced class="rounded-lg border border-slate-200 p-4">
                    <summary class="cursor-pointer text-sm font-semibold">{{ t('rbacPermissionAdvanced') }}</summary>
                    <p class="mt-3 text-sm text-slate-600">{{ t('rbacPermissionAdvancedHelp') }}</p>
                    <div class="mt-3 overflow-x-auto"><table class="w-full text-left text-sm">
                        <caption class="sr-only">{{ t('rbacPermissionAdvanced') }}</caption>
                        <thead><tr><th class="p-2">{{ t('rbacPermissionName') }}</th><th class="p-2">{{ t('rbacPermissionCode') }}</th><th class="p-2">{{ t('uiStatus') }}</th></tr></thead>
                        <tbody><tr v-for="permission in permissions" :key="permission.key" class="border-t border-slate-100"><td class="p-2">{{ permissionLabel(permission) }}</td><td class="p-2 font-mono">{{ permission.key }}</td><td class="p-2">{{ t(!permission.available ? 'rbacPermissionUnavailable' : roleForm.permission_keys.includes(permission.key) ? 'rbacPermissionSelected' : 'rbacPermissionNotSelected') }}</td></tr></tbody>
                    </table></div>
                    <fieldset v-if="advancedPermissions.length" class="mt-4 flex flex-col gap-4" :disabled="securityReadOnly">
                        <legend class="mb-3 text-sm font-semibold">{{ t('rbacPermissionOther') }}</legend>
                        <label v-for="permission in advancedPermissions" :key="permission.key" class="flex items-start gap-3 text-sm">
                            <input v-model="roleForm.permission_keys" type="checkbox" :value="permission.key" :disabled="securityReadOnly || !permission.available || !permission.delegatable" class="mt-1 size-4 shrink-0 accent-teal-700" />
                            <span>{{ permissionLabel(permission) }}<span v-if="!permission.available" class="block text-xs text-slate-500">{{ t('rbacPermissionUnavailable') }}</span></span>
                        </label>
                    </fieldset>
                </details>
                <p v-if="fieldError('permission_keys')" role="alert" class="text-sm text-red-700">{{ t(fieldError('permission_keys')) }}</p>
                <div class="sticky -bottom-5 -mx-6 -mb-5 flex flex-wrap justify-end gap-3 border-t border-slate-200 bg-white px-6 py-4"><UiButton variant="secondary" :disabled="saving" @click="editorOpen = false">{{ t('uiCancel') }}</UiButton><UiButton v-if="can('roles.manage') && !readOnly" type="submit" :loading="saving">{{ t('rbacSaveRole') }}</UiButton></div>
            </form>
            </FormModal>

            <section v-show="section === 'roles'" v-if="can('assignments.view')" class="flex flex-col gap-4" aria-labelledby="assignments-heading">
                <div class="flex flex-wrap items-center justify-between gap-3"><h3 id="assignments-heading" class="font-semibold">{{ t('rbacAssignments') }}</h3><UiButton v-if="can('assignments.manage') && can('users.view') && can('roles.view')" data-open-assign :disabled="loading || saving" @click="errorKey = ''; assignOpen = true">{{ t('rbacAssignRole') }}</UiButton></div>
                <DataTable v-model:page="assignmentPage" :rows="assignmentRows" :columns="assignmentColumns" :caption="t('rbacAssignments')" :loading="loading" server-side :total="assignmentTotal" :per-page="10" @change="changeAssignmentPage">
                    <template #cell-status="{ row }"><StatusBadge :status="row.active ? 'effective' : 'inactive'" :label="row.status" /></template>
                    <template #cell-actions="{ row }"><IconButton v-if="can('assignments.manage') && row.active && row.revocable" action="delete" :label="t('rbacRevoke')" :disabled="saving" @click="prepareRevoke(row)" /></template>
                </DataTable>
            </section>

            <FormModal v-if="assignOpen && section === 'roles' && can('assignments.manage') && can('users.view') && can('roles.view')" :title="t('rbacAssignRole')" :subtitle="t('rbacEligibleNotice')" :busy="saving" @close="assignOpen = false">
            <section class="flex flex-col gap-5" aria-labelledby="grant-heading">
                <h4 id="grant-heading" class="sr-only">{{ t('rbacAssignRole') }}</h4>
                <p v-if="errorKey" role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ t(errorKey) }}</p>
                <div class="grid grid-cols-1 items-end gap-4 rounded-xl border border-slate-200 bg-slate-50 p-4 sm:grid-cols-2">
                    <TextInput v-model="userSearch" :label="t('rbacAccountSearch')" />
                    <TextInput v-model="roleSearch" :label="t('rbacRoleSearch')" />
                    <div class="flex justify-end sm:col-span-2"><UiButton variant="secondary" :loading="searchLoading" :disabled="saving || loading" @click="searchCandidates">{{ t('rbacFindCandidates') }}</UiButton></div>
                </div>
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2"><SelectInput v-model="targetUser" :label="t('rbacAccount')" :options="candidateUsers" :disabled="saving || searchLoading" /><SelectInput v-model="targetRole" :label="t('rbacRole')" :options="candidateRoles" :disabled="saving || searchLoading" /></div>
                <p v-if="!searchLoading && users.length === 0" class="text-xs text-slate-500">{{ t('rbacNoCandidates') }}</p>
                <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 pt-4"><UiButton variant="secondary" :disabled="saving" @click="assignOpen = false">{{ t('uiCancel') }}</UiButton><UiButton :disabled="saving || loading || !targetUser || !targetRole" @click="prepareAssignment">{{ t('rbacAssignRole') }}</UiButton></div>
            </section>
            </FormModal>
        </template>
        <ConfirmDialog v-model="confirmationOpen" :danger="pending?.kind === 'revoke'" :title="t(pending?.kind === 'assign' ? 'rbacAssignRole' : 'rbacRevoke')" :message="confirmationMessage" :loading="saving" @confirm="confirmAction" />
        <BusyOverlay />
    </section>
</template>
