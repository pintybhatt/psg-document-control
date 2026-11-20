<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import UiButton from './ui/UiButton.vue';
import StatusBadge from './ui/StatusBadge.vue';
import IconButton from './ui/IconButton.vue';
import TextInput from './ui/TextInput.vue';
import SelectInput from './ui/SelectInput.vue';
import CheckboxInput from './ui/CheckboxInput.vue';
import ConfirmDialog from './ui/SweetConfirmDialog.vue';
import FormModal from './ui/FormModal.vue';
import { showSuccess } from '../utilities/alerts.js';
import { createJsonClient } from '../utilities/jsonClient.js';
import { useLocalization } from '../localization/index.js';

const props = defineProps({ endpoint: { type: String, required: true }, accessEndpoints: { type: Object, default: () => ({}) }, csrfToken: { type: String, required: true }, currentUserId: { type: Number, required: true } });
const { t, language } = useLocalization();
const request = createJsonClient(props.csrfToken);
const users = ref([]);
const search = ref('');
const page = ref(1);
const lastPage = ref(1);
const loading = ref(false);
const saving = ref(false);
const editing = ref(null);
const editor = ref(null);
const error = ref('');
const fields = ref({});
const form = reactive({ name: '', email: '', preferred_language: 'en', password: '', password_confirmation: '', active: true });
const accessForm = reactive({ company_id: '', site_id: '', department_access: '', department_ids: [], role_id: '', confirm_regrant: false });
const companies = ref([]);
const sites = ref([]);
const departments = ref([]);
const roles = ref(null);
const companyOnly = 'company';
const hasSite = computed(() => !!accessForm.site_id && accessForm.site_id !== companyOnly);
const eligibleRoles = computed(() => roles.value ?? []);
const departmentModes = { all: 'userAccessAllDepartments', selected: 'userAccessSelectedDepartments', site_wide: 'userAccessSiteWideDocuments' };
const departmentModeOptions = computed(() => Object.entries(departmentModes).map(([value, key]) => ({ value, label: t(key) })));
const needsDepartments = computed(() => accessForm.department_access === 'selected' && !accessForm.department_ids.length);
const existingAccess = ref({ memberships: [], assignments: [] });
const accessLoading = ref(false);
const loadingOptions = reactive({ companies: false, sites: false, scope: false });
const optionsLoading = computed(() => Object.values(loadingOptions).some(Boolean));
const pending = ref(null);
const confirming = ref(false);
const accessEnabled = computed(() => !!props.accessEndpoints.units && editing.value?.id !== props.currentUserId);
const label = record => record?.[language.value === 'lo' ? 'name_lo' : 'name_en'] ?? '';
const options = (rows, placeholder, optionLabel = label) => [{ value: '', label: t(placeholder) }, ...rows.map(row => ({ value: row.id, label: optionLabel(row) }))];
const siteOptions = computed(() => {
    const [placeholder, ...siteChoices] = options(sites.value, 'userAccessChooseScope');
    return [placeholder, { value: companyOnly, label: t('userAccessCompanyOnly') }, ...siteChoices];
});
const roleOptions = computed(() => options(eligibleRoles.value, 'userAccessChooseRole', role => {
    const scope = role.scope_key === 'global' ? 'rbacGlobal' : role.scope_key.startsWith('company:') ? 'userAccessCompany' : 'userAccessSite';
    return `${label(role)} · ${t(scope)}`;
}));
const selectedRole = computed(() => eligibleRoles.value.find(role => String(role.id) === String(accessForm.role_id)));
const missingWorkspaceGrant = computed(() => selectedRole.value && !selectedRole.value.permission_keys.includes('documents.metadata.view'));
const workspaceWarning = computed(() => {
    if (missingWorkspaceGrant.value) {
        return selectedRole.value.standard ? 'userAccessStandardWorkspaceMissing' : 'userAccessCustomWorkspaceMissing';
    }
    return '';
});
const accessLocation = row => row.scope_key === 'global' ? t('rbacGlobal') : [label(row.company), label(row.site), label(row.department)].filter(Boolean).join(' / ');
const accessLabel = row => [label(row.role), accessLocation(row)].filter(Boolean).join(' · ');
const validationKeys = {
    'The name field is required.': 'rbacRequired',
    'The email field is required.': 'rbacRequired',
    'The password field is required.': 'rbacRequired',
    'The email field must be a valid email address.': 'userEmailInvalid',
    'The email has already been taken.': 'userEmailTaken',
    'The password field must be at least 8 characters.': 'userPasswordMin',
    'The password field confirmation does not match.': 'profilePasswordMismatch',
    'Password must be no more than 72 bytes and contain no null bytes.': 'userPasswordInvalid',
};
let editorController;
let siteController;
let scopeController;

watch(selectedRole, role => {
    const defaults = { DOCUMENT_CONTROLLER: 'all', VIEWER: 'all', DEPARTMENT_USER: 'selected' };
    accessForm.department_access = role ? defaults[role.code] ?? 'site_wide' : '';
    accessForm.department_ids = [];
});

function toggleDepartment(id, selected) {
    accessForm.department_ids = selected
        ? [...accessForm.department_ids, id]
        : accessForm.department_ids.filter(departmentId => departmentId !== id);
}

function handleError(exception) {
    if (exception.name === 'AbortError') { return; }
    fields.value = Object.fromEntries(Object.entries(exception.fields ?? {}).map(([field, messages]) =>
        [field, messages.map(message => Object.hasOwn(validationKeys, message) ? validationKeys[message] : message)]));
    error.value = Object.values(fields.value).flat()[0] ?? exception.message ?? 'userRequestFailed';
    if ([401, 403, 419].includes(exception.status)) {
        editorController?.abort(); siteController?.abort(); scopeController?.abort();
        existingAccess.value = { memberships: [], assignments: [] };
        companies.value = []; sites.value = []; departments.value = []; roles.value = null;
        editing.value = null; confirming.value = false; pending.value = null;
    }
}

async function allOptions(endpoint, parameters, signal) {
    const rows = [];
    let currentPage = 1;
    let result;
    do {
        result = await request(`${endpoint}?${new URLSearchParams({ ...parameters, page: currentPage++, per_page: 50 })}`, { signal });
        rows.push(...result.data);
    } while (currentPage <= (result.meta?.last_page ?? result.last_page ?? 1));
    return rows;
}

async function loadAccess(signal = editorController?.signal) {
    if (!editing.value?.id) { return; }
    const id = editing.value.id;
    accessLoading.value = true;
    try {
        const result = await request(`${props.endpoint}/${id}/access`, { signal });
        if (!signal?.aborted && editing.value?.id === id) { existingAccess.value = result; }
    } catch (exception) { if (!signal?.aborted) { handleError(exception); } }
    finally { if (!signal?.aborted) { accessLoading.value = false; } }
}

watch(() => accessForm.company_id, async companyId => {
    siteController?.abort(); scopeController?.abort();
    siteController = new AbortController();
    const signal = siteController.signal;
    accessForm.site_id = ''; accessForm.role_id = '';
    sites.value = []; departments.value = []; roles.value = null; loadingOptions.sites = false; loadingOptions.scope = false;
    if (!companyId) { return; }
    loadingOptions.sites = true;
    try {
        const result = await allOptions(props.accessEndpoints.units, { scope_type: 'company', company_id: companyId, unit_type: 'site' }, signal);
        if (!signal.aborted) { sites.value = result; }
    } catch (exception) { if (!signal.aborted) { handleError(exception); } }
    finally { if (!signal.aborted) { loadingOptions.sites = false; } }
});

watch(() => accessForm.site_id, async siteId => {
    scopeController?.abort(); scopeController = new AbortController();
    const signal = scopeController.signal;
    accessForm.role_id = '';
    departments.value = []; roles.value = null; loadingOptions.scope = false;
    if (!siteId) { return; }
    loadingOptions.scope = true;
    const scope = hasSite.value
        ? { scope_type: 'site', company_id: accessForm.company_id, site_id: siteId }
        : { scope_type: 'company', company_id: accessForm.company_id };
    try {
        const [units, candidates, permissions] = await Promise.all([
            allOptions(props.accessEndpoints.units, { ...scope, unit_type: 'department' }, signal),
            allOptions(props.accessEndpoints.roles, scope, signal),
            request(`${props.accessEndpoints.permissions}?${new URLSearchParams(scope)}`, { signal }),
        ]);
        if (signal.aborted) { return; }
        departments.value = units;
        roles.value = candidates.filter(role => role.active && role.assignable && role.code !== 'SECURITY_ADMINISTRATOR'
            && role.permission_keys.every(key => permissions.data.some(permission => permission.key === key && permission.available && permission.delegatable)));
    } catch (exception) { if (!signal.aborted) { handleError(exception); } }
    finally { if (!signal.aborted) { loadingOptions.scope = false; } }
});

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const response = await request(`${props.endpoint}?${new URLSearchParams({ q: search.value, page: page.value })}`);
        users.value = response.data;
        lastPage.value = response.last_page;
    } catch (exception) { handleError(exception); }
    finally { loading.value = false; }
}

async function open(user = null) {
    editorController?.abort(); siteController?.abort(); scopeController?.abort();
    editorController = new AbortController();
    const signal = editorController.signal;
    editing.value = user ?? { id: null };
    Object.assign(accessForm, { company_id: '', site_id: '', department_access: '', department_ids: [], role_id: '', confirm_regrant: false });
    existingAccess.value = { memberships: [], assignments: [] };
    companies.value = []; sites.value = []; departments.value = []; roles.value = null;
    accessLoading.value = false; Object.assign(loadingOptions, { companies: false, sites: false, scope: false }); pending.value = null; confirming.value = false;
    Object.assign(form, { name: user?.name ?? '', email: user?.email ?? '', preferred_language: user?.preferred_language ?? 'en', password: '', password_confirmation: '', active: user?.active ?? true });
    fields.value = {};
    error.value = '';
    if (!props.accessEndpoints.units) { return; }
    loadingOptions.companies = true;
    try {
        await Promise.all([
            allOptions(props.accessEndpoints.units, { scope_type: 'global', unit_type: 'company' }, signal).then(rows => { if (!signal.aborted) { companies.value = rows; } }),
            user ? loadAccess(signal) : Promise.resolve(),
        ]);
    } catch (exception) { if (!signal.aborted) { handleError(exception); } }
    finally { if (!signal.aborted) { loadingOptions.companies = false; } }
}

async function save() {
    if (saving.value || optionsLoading.value || accessLoading.value || !editing.value
        || (accessEnabled.value && accessForm.company_id && (!accessForm.site_id || !accessForm.role_id || !accessForm.department_access || needsDepartments.value))) { return; }
    saving.value = true;
    error.value = '';
    fields.value = {};
    const updating = editing.value.id !== null;
    const body = { name: form.name, email: form.email, preferred_language: form.preferred_language };
    if (updating && editing.value.id !== props.currentUserId) { body.active = form.active; }
    if (!updating || form.password) { body.password = form.password; body.password_confirmation = form.password_confirmation; }
    if (accessEnabled.value && accessForm.company_id) {
        body.access = { company_id: Number(accessForm.company_id), site_id: hasSite.value ? Number(accessForm.site_id) : null,
            role_id: Number(accessForm.role_id), department_access: accessForm.department_access,
            confirm_regrant: accessForm.confirm_regrant };
        if (accessForm.department_access === 'selected') { body.access.department_ids = accessForm.department_ids; }
    }
    try {
        await request(updating ? `${props.endpoint}/${editing.value.id}` : props.endpoint, { method: updating ? 'PATCH' : 'POST', body });
        editing.value = null;
        showSuccess(t('uiSaveSuccess'), t('userSaved'));
        await load();
    } catch (exception) {
        handleError(exception);
        await nextTick();
        const invalidField = editor.value?.querySelector('[aria-invalid="true"]');
        (invalidField ?? editor.value?.querySelector('[data-user-error]'))?.focus();
    } finally { saving.value = false; }
}

function confirmRevoke(row, kind) { pending.value = { ...row, kind }; confirming.value = true; }
async function revokeAccess() {
    if (saving.value || !pending.value) { return; }
    saving.value = true;
    try {
        await request(pending.value.revoke_url, { method: 'DELETE' });
        confirming.value = false; pending.value = null;
        showSuccess(t('uiSaveSuccess'));
        await loadAccess();
    } catch (exception) { handleError(exception); }
    finally { saving.value = false; }
}

function closeEditor() {
    if (saving.value) { return; }
    editing.value = null; editorController?.abort(); siteController?.abort(); scopeController?.abort();
}

async function changePage(direction) {
    page.value += direction;
    await load();
}

onMounted(load);
onBeforeUnmount(() => { editorController?.abort(); siteController?.abort(); scopeController?.abort(); });
</script>

<template>
    <section class="flex flex-col gap-4" aria-labelledby="users-heading">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h3 id="users-heading" class="text-lg font-semibold">{{ t('userManagementTitle') }}</h3>
            <UiButton :disabled="saving" @click="open()">{{ t('userCreate') }}</UiButton>
        </div>
        <form class="flex flex-wrap items-end gap-3" @submit.prevent="page = 1; load()">
            <TextInput v-model="search" type="search" :label="t('userSearch')" :placeholder="t('userSearchPlaceholder')" />
            <UiButton type="submit" variant="secondary" :loading="loading">{{ t('userFind') }}</UiButton>
        </form>
        <p v-if="error && !editing" role="alert" class="text-sm text-red-700">{{ t(error) }}</p>
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50"><tr><th class="p-3">{{ t('userName') }}</th><th class="p-3">{{ t('userEmail') }}</th><th class="p-3">{{ t('uiStatus') }}</th><th class="p-3">{{ t('rbacActions') }}</th></tr></thead>
                <tbody><tr v-for="user in users" :key="user.id" class="border-t border-slate-200"><td class="p-3">{{ user.name }}</td><td class="p-3">{{ user.email }}</td><td class="p-3"><StatusBadge :status="user.active ? 'active' : 'inactive'" :label="t(user.active ? 'rbacActive' : 'rbacInactive')" /></td><td class="p-3"><IconButton action="edit" :label="t('rbacEdit')" :disabled="saving" @click="open(user)" /></td></tr></tbody>
            </table>
            <p v-if="!loading && !users.length" class="p-4 text-sm text-slate-500">{{ t('userNone') }}</p>
        </div>
        <div class="flex items-center gap-3"><UiButton variant="secondary" :disabled="page <= 1 || loading" @click="changePage(-1)">{{ t('userPrevious') }}</UiButton><span class="text-sm">{{ page }} / {{ lastPage }}</span><UiButton variant="secondary" :disabled="page >= lastPage || loading" @click="changePage(1)">{{ t('userNext') }}</UiButton></div>
        <FormModal v-if="editing" :title="t(editing.id === null ? 'userCreate' : 'userEdit')" :subtitle="editing.id === null ? '' : editing.email" :busy="saving" @close="closeEditor">
        <form ref="editor" @submit.prevent="save">
            <p v-if="error" data-user-error role="alert" tabindex="-1" class="mb-3 rounded-lg bg-red-50 p-3 text-sm text-red-800">{{ t(error) }}</p>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <TextInput v-model="form.name" :label="t('userName')" required maxlength="255" :error-key="fields.name?.[0]" />
                <TextInput v-model="form.email" :label="t('userEmail')" type="email" required maxlength="255" :error-key="fields.email?.[0]" />
                <div :class="{ 'sm:col-span-2': editing.id === null || editing.id === currentUserId }"><SelectInput v-model="form.preferred_language" :label="t('userLanguage')" :options="[{ value: 'en', label: 'English' }, { value: 'lo', label: 'ລາວ' }]" /></div>
                <div v-if="editing.id !== null && editing.id !== currentUserId" class="flex items-end"><div class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5"><CheckboxInput v-model="form.active" :label="t('userActive')" /></div></div>
                <TextInput v-model="form.password" type="password" autocomplete="new-password" :label="t(editing.id === null ? 'userInitialPassword' : 'userResetPassword')" :required="editing.id === null" :error-key="fields.password?.[0]" />
                <TextInput v-model="form.password_confirmation" type="password" autocomplete="new-password" :label="t('userConfirmPassword')" :required="editing.id === null || !!form.password" />
                <p class="text-xs text-slate-600 sm:col-span-2">{{ t('userPasswordHelp') }}</p>
            </div>
            <section v-if="editing.id !== null && accessEndpoints.units" class="mt-4 rounded-xl border border-slate-200 bg-white p-3" :aria-label="t('userAccessCurrent')">
                <h4 class="font-semibold">{{ t('userAccessCurrent') }}</h4>
                <div class="mt-2 grid gap-3 sm:grid-cols-2">
                    <div v-for="kind in ['memberships', 'assignments']" :key="kind" class="min-w-0">
                        <h5 class="text-sm font-medium text-slate-600">{{ t(kind === 'memberships' ? 'membershipTitle' : 'userAccessAssignedRoles') }}</h5>
                        <p v-if="!accessLoading && !existingAccess[kind].length" class="mt-1 text-sm text-slate-600">{{ t('userAccessNone') }}</p>
                        <ul v-else class="divide-y divide-slate-100">
                            <li v-for="row in existingAccess[kind]" :key="row.id" class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                                <div class="min-w-0 wrap-anywhere">
                                    <p class="font-medium">{{ kind === 'memberships' ? accessLocation(row) : label(row.role) }}</p>
                                    <p v-if="kind === 'assignments'" class="text-xs text-slate-600">{{ accessLocation(row) }}</p>
                                    <p v-else class="text-xs text-slate-600">{{ t(row.department ? departmentModes.selected : row.all_departments ? departmentModes.all : departmentModes.site_wide) }}</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <StatusBadge :status="row.effective ? 'active' : 'inactive'" :label="t(row.effective ? 'rbacActive' : 'rbacInactive')" />
                                    <IconButton v-if="row.revoke_url" action="delete" :label="t(kind === 'memberships' ? 'membershipRevoke' : 'rbacRevoke')" :aria-label="`${t(kind === 'memberships' ? 'membershipRevoke' : 'rbacRevoke')} · ${accessLabel(row)}`" :disabled="saving || accessLoading" @click="confirmRevoke(row, kind)" />
                                </div>
                            </li>
                        </ul>
                    </div>
                </div>
            </section>
            <fieldset v-if="accessEnabled" :disabled="saving || optionsLoading || accessLoading" class="mt-4 grid grid-cols-1 gap-3 rounded-xl border border-slate-200 bg-white p-3 sm:grid-cols-2">
                <legend class="px-2 font-semibold">{{ t('userAccessAdd') }}</legend>
                <p class="text-sm text-slate-600 sm:col-span-2">{{ t('userAccessHelp') }}</p>
                <p v-if="editing.id !== null && accessForm.company_id" class="text-sm text-slate-600 sm:col-span-2">{{ t('userAccessExistingCoverageHelp') }}</p>
                <SelectInput v-model="accessForm.company_id" :label="t('userAccessCompany')" :options="options(companies, 'userAccessChooseCompany')" :error-key="fields['access.company_id']?.[0]" />
                <SelectInput v-model="accessForm.site_id" :label="t('userAccessScope')" :options="siteOptions" :disabled="!accessForm.company_id" :required="!!accessForm.company_id" :error-key="fields['access.site_id']?.[0]" />
                <SelectInput v-model="accessForm.role_id" :label="t('userAccessRole')" :options="roleOptions" :disabled="!eligibleRoles.length" :required="!!accessForm.company_id" :error-key="fields['access.role_id']?.[0]" />
                <SelectInput v-if="selectedRole" v-model="accessForm.department_access" :label="t('userAccessDepartmentCoverage')" :options="departmentModeOptions" required :error-key="fields['access.department_access']?.[0]" />
                <fieldset v-if="selectedRole && accessForm.department_access === 'selected'" class="min-w-0 rounded-lg border border-slate-200 p-3 sm:col-span-2">
                    <legend class="px-1 text-sm font-medium">{{ t('userAccessChooseDepartments') }}</legend>
                    <p v-if="!departments.length" class="text-sm text-slate-600">{{ t('userAccessNoDepartments') }}</p>
                    <div v-else class="grid max-h-48 gap-2 overflow-y-auto p-1 sm:grid-cols-2">
                        <CheckboxInput v-for="department in departments" :key="department.id"
                            :label="hasSite ? label(department) : [label(department.site), label(department)].join(' / ')"
                            :model-value="accessForm.department_ids.includes(department.id)"
                            @update:model-value="toggleDepartment(department.id, $event)" />
                    </div>
                    <p v-if="fields['access.department_ids']" role="alert" class="mt-1 text-sm text-red-700">{{ t(fields['access.department_ids'][0]) }}</p>
                </fieldset>
                <p v-if="selectedRole?.description" class="line-clamp-2 text-sm text-slate-600 sm:col-span-2">{{ selectedRole.description }}</p>
                <p v-if="workspaceWarning" role="status" class="text-sm text-amber-900 sm:col-span-2">{{ t(workspaceWarning) }}</p>
                <p v-if="roles && !eligibleRoles.length" role="status" class="text-sm text-slate-600 sm:col-span-2">{{ t('userAccessNoRoles') }}</p>
                <div v-if="editing.id !== null && accessForm.company_id" class="sm:col-span-2"><CheckboxInput v-model="accessForm.confirm_regrant" :label="t('userAccessConfirmRegrant')" /></div>
            </fieldset>
            <p v-if="optionsLoading || accessLoading" role="status" class="mt-3 text-sm">{{ t('uiLoading') }}</p>
            <div class="sticky -bottom-5 -mx-6 -mb-5 mt-4 flex flex-wrap justify-end gap-2 border-t border-slate-200 bg-white px-6 py-3"><UiButton variant="secondary" :disabled="saving" @click="closeEditor">{{ t('uiCancel') }}</UiButton><UiButton type="submit" :loading="saving" :disabled="optionsLoading || accessLoading || (!!accessForm.company_id && (!eligibleRoles.length || needsDepartments))">{{ t('userSave') }}</UiButton></div>
        </form>
        </FormModal>
        <ConfirmDialog v-if="pending" v-model="confirming" danger :title="t(pending.kind === 'memberships' ? 'membershipRevoke' : 'rbacRevoke')" :message="`${t('userAccessRevokeConfirm')} ${accessLabel(pending)}`" :loading="saving" @confirm="revokeAccess" />
    </section>
</template>
