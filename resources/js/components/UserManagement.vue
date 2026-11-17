<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
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
const error = ref('');
const saved = ref(false);
const fields = ref({});
const form = reactive({ name: '', email: '', preferred_language: 'en', password: '', password_confirmation: '', active: true });
const accessForm = reactive({ company_id: '', site_id: '', department_id: '', role_id: '', confirm_regrant: false });
const companies = ref([]);
const sites = ref([]);
const departments = ref([]);
const roles = ref([]);
const eligibleRoles = computed(() => roles.value.filter(role => !accessForm.department_id || (role.code !== 'DOCUMENT_CONTROLLER'
    && !role.permission_keys.some(key => ['numbering.issue', 'numbering.view', 'numbering.manage', 'master-data.view', 'master-data.manage'].includes(key)))));
const existingAccess = ref({ memberships: [], assignments: [] });
const accessLoading = ref(false);
const loadingOptions = reactive({ companies: false, sites: false, scope: false });
const optionsLoading = computed(() => Object.values(loadingOptions).some(Boolean));
const pending = ref(null);
const confirming = ref(false);
const accessEnabled = computed(() => !!props.accessEndpoints.units && editing.value?.id !== props.currentUserId);
const label = record => record?.[language.value === 'lo' ? 'name_lo' : 'name_en'] ?? '';
const options = rows => [{ value: '', label: t('uiChoose') }, ...rows.map(row => ({ value: row.id, label: label(row) }))];
const accessLabel = row => [label(row.company), label(row.site), label(row.department), label(row.role), row.scope_key].filter(Boolean).join(' · ');
let editorController;
let siteController;
let scopeController;

watch(() => accessForm.department_id, () => {
    if (!eligibleRoles.value.some(role => String(role.id) === String(accessForm.role_id))) { accessForm.role_id = ''; }
});

function handleError(exception) {
    if (exception.name === 'AbortError') { return; }
    fields.value = exception.fields ?? {};
    error.value = t(Object.values(fields.value).flat()[0] ?? exception.message ?? 'userRequestFailed');
    if ([401, 403, 419].includes(exception.status)) {
        editorController?.abort(); siteController?.abort(); scopeController?.abort();
        existingAccess.value = { memberships: [], assignments: [] };
        companies.value = []; sites.value = []; departments.value = []; roles.value = [];
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
    accessForm.site_id = ''; accessForm.department_id = ''; accessForm.role_id = '';
    sites.value = []; departments.value = []; roles.value = []; loadingOptions.sites = false; loadingOptions.scope = false;
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
    accessForm.department_id = ''; accessForm.role_id = '';
    departments.value = []; roles.value = []; loadingOptions.scope = false;
    if (!siteId) { return; }
    loadingOptions.scope = true;
    const scope = { scope_type: 'site', company_id: accessForm.company_id, site_id: siteId };
    try {
        const [units, candidates, permissions] = await Promise.all([
            allOptions(props.accessEndpoints.units, { ...scope, unit_type: 'department' }, signal),
            allOptions(props.accessEndpoints.roles, scope, signal),
            request(`${props.accessEndpoints.permissions}?${new URLSearchParams(scope)}`, { signal }),
        ]);
        if (signal.aborted) { return; }
        departments.value = units;
        roles.value = candidates.filter(role => role.active && role.assignable && role.code !== 'SECURITY_ADMINISTRATOR'
            && ['organization.access', 'documents.metadata.view'].every(key => role.permission_keys.includes(key))
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
    Object.assign(accessForm, { company_id: '', site_id: '', department_id: '', role_id: '', confirm_regrant: false });
    existingAccess.value = { memberships: [], assignments: [] };
    companies.value = []; sites.value = []; departments.value = []; roles.value = [];
    accessLoading.value = false; Object.assign(loadingOptions, { companies: false, sites: false, scope: false }); pending.value = null; confirming.value = false;
    Object.assign(form, { name: user?.name ?? '', email: user?.email ?? '', preferred_language: user?.preferred_language ?? 'en', password: '', password_confirmation: '', active: user?.active ?? true });
    fields.value = {};
    saved.value = false;
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
    if (saving.value || optionsLoading.value || accessLoading.value || !editing.value) { return; }
    saving.value = true;
    error.value = '';
    fields.value = {};
    const updating = editing.value.id !== null;
    const body = { name: form.name, email: form.email, preferred_language: form.preferred_language };
    if (updating && editing.value.id !== props.currentUserId) { body.active = form.active; }
    if (!updating || form.password) { body.password = form.password; body.password_confirmation = form.password_confirmation; }
    if (accessEnabled.value && accessForm.company_id) {
        body.access = { company_id: Number(accessForm.company_id), site_id: accessForm.site_id ? Number(accessForm.site_id) : null,
            department_id: accessForm.department_id ? Number(accessForm.department_id) : null, role_id: accessForm.role_id ? Number(accessForm.role_id) : null,
            confirm_regrant: accessForm.confirm_regrant };
    }
    try {
        await request(updating ? `${props.endpoint}/${editing.value.id}` : props.endpoint, { method: updating ? 'PATCH' : 'POST', body });
        editing.value = null;
        saved.value = true;
        showSuccess(t('uiSaveSuccess'), t('userSaved'));
        await load();
    } catch (exception) {
        handleError(exception);
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
            <h3 id="users-heading" class="font-semibold">{{ t('userManagementTitle') }}</h3>
            <UiButton :disabled="saving" @click="open()">{{ t('userCreate') }}</UiButton>
        </div>
        <form class="flex flex-wrap items-end gap-3" @submit.prevent="page = 1; load()">
            <TextInput v-model="search" :label="t('userSearch')" />
            <UiButton type="submit" variant="secondary" :loading="loading">{{ t('userFind') }}</UiButton>
        </form>
        <p v-if="error && !editing" role="alert" class="text-sm text-red-700">{{ error }}</p>
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50"><tr><th class="p-3">{{ t('userName') }}</th><th class="p-3">{{ t('userEmail') }}</th><th class="p-3">{{ t('uiStatus') }}</th><th class="p-3">{{ t('rbacActions') }}</th></tr></thead>
                <tbody><tr v-for="user in users" :key="user.id" class="border-t border-slate-200"><td class="p-3">{{ user.name }}</td><td class="p-3">{{ user.email }}</td><td class="p-3"><StatusBadge :status="user.active ? 'active' : 'inactive'" :label="t(user.active ? 'rbacActive' : 'rbacInactive')" /></td><td class="p-3"><IconButton action="edit" :label="t('rbacEdit')" :disabled="saving" @click="open(user)" /></td></tr></tbody>
            </table>
            <p v-if="!loading && !users.length" class="p-4 text-sm text-slate-500">{{ t('userNone') }}</p>
        </div>
        <div class="flex items-center gap-3"><UiButton variant="secondary" :disabled="page <= 1 || loading" @click="changePage(-1)">{{ t('userPrevious') }}</UiButton><span class="text-sm">{{ page }} / {{ lastPage }}</span><UiButton variant="secondary" :disabled="page >= lastPage || loading" @click="changePage(1)">{{ t('userNext') }}</UiButton></div>
        <FormModal v-if="editing" :title="t(editing.id === null ? 'userCreate' : 'userEdit')" :subtitle="editing.id === null ? '' : editing.email" :busy="saving" @close="closeEditor">
        <form @submit.prevent="save">
            <p v-if="error" role="alert" class="mb-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ error }}</p>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <TextInput v-model="form.name" :label="t('userName')" required maxlength="255" :error="fields.name?.[0]" />
                <TextInput v-model="form.email" :label="t('userEmail')" type="email" required maxlength="255" :error="fields.email?.[0]" />
                <div :class="{ 'sm:col-span-2': editing.id === null || editing.id === currentUserId }"><SelectInput v-model="form.preferred_language" :label="t('userLanguage')" :options="[{ value: 'en', label: 'English' }, { value: 'lo', label: 'ລາວ' }]" /></div>
                <div v-if="editing.id !== null && editing.id !== currentUserId" class="flex items-end"><div class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5"><CheckboxInput v-model="form.active" :label="t('userActive')" /></div></div>
                <TextInput v-model="form.password" type="password" autocomplete="new-password" :label="t(editing.id === null ? 'userInitialPassword' : 'userResetPassword')" :required="editing.id === null" :error="fields.password?.[0]" />
                <TextInput v-model="form.password_confirmation" type="password" autocomplete="new-password" :label="t('userConfirmPassword')" :required="editing.id === null || !!form.password" />
            </div>
            <fieldset v-if="accessEnabled" :disabled="saving || optionsLoading || accessLoading" class="mt-5 grid grid-cols-1 gap-4 rounded-xl border border-slate-200 bg-slate-50/60 p-4 sm:grid-cols-2">
                <legend class="px-2 font-semibold">{{ t('userAccessAdd') }}</legend>
                <p class="text-sm text-slate-600 sm:col-span-2">{{ t('userAccessHelp') }}</p>
                <SelectInput v-model="accessForm.company_id" :label="t('userAccessCompany')" :options="options(companies)" :error-key="fields['access.company_id']?.[0]" />
                <SelectInput v-model="accessForm.site_id" :label="t('userAccessSite')" :options="options(sites)" :disabled="!accessForm.company_id" :required="!!accessForm.company_id" :error-key="fields['access.site_id']?.[0]" />
                <SelectInput v-model="accessForm.department_id" :label="t('userAccessDepartment')" :options="options(departments)" :disabled="!accessForm.site_id" :error-key="fields['access.department_id']?.[0]" />
                <SelectInput v-model="accessForm.role_id" :label="t('userAccessRole')" :options="options(eligibleRoles)" :disabled="!accessForm.site_id" :required="!!accessForm.company_id" :error-key="fields['access.role_id']?.[0]" />
                <p v-if="accessForm.department_id" class="text-sm text-slate-600 sm:col-span-2">{{ t('userAccessSiteRole') }}</p>
                <p v-if="accessForm.site_id && !eligibleRoles.length && !optionsLoading" class="text-sm text-slate-600 sm:col-span-2">{{ t('userAccessNoRoles') }}</p>
                <div v-if="editing.id !== null && accessForm.company_id" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 sm:col-span-2"><CheckboxInput v-model="accessForm.confirm_regrant" :label="t('membershipConfirmRegrant')" /></div>
            </fieldset>
            <p v-if="optionsLoading || accessLoading" role="status" class="mt-3 text-sm">{{ t('uiLoading') }}</p>
            <section v-if="editing.id !== null && accessEndpoints.units" class="mt-5 flex flex-col gap-3" :aria-label="t('userAccessCurrent')">
                <h4 class="font-semibold">{{ t('userAccessCurrent') }}</h4>
                <p class="text-sm text-slate-600">{{ t('userAccessExistingHelp') }}</p>
                <div v-for="kind in ['memberships', 'assignments']" :key="kind">
                    <h5 class="text-sm font-semibold">{{ t(kind === 'memberships' ? 'membershipTitle' : 'rbacAssignments') }}</h5>
                    <p v-if="!accessLoading && !existingAccess[kind].length" class="text-sm">{{ t('userAccessNone') }}</p>
                    <ul class="flex flex-col gap-2"><li v-for="row in existingAccess[kind]" :key="row.id" class="flex flex-wrap items-center justify-between gap-2 text-sm">
                        <span>{{ accessLabel(row) }} · {{ t(row.effective ? 'rbacActive' : 'rbacInactive') }}</span>
                        <IconButton v-if="row.revoke_url" action="delete" :label="t(kind === 'memberships' ? 'membershipRevoke' : 'rbacRevoke')" :disabled="saving || accessLoading" @click="confirmRevoke(row, kind)" />
                    </li></ul>
                </div>
            </section>
            <p class="my-3 text-xs text-slate-600">{{ t('userPasswordHelp') }}</p>
            <div class="flex flex-wrap justify-end gap-3 border-t border-slate-200 pt-4"><UiButton variant="secondary" :disabled="saving" @click="closeEditor">{{ t('uiCancel') }}</UiButton><UiButton type="submit" :loading="saving" :disabled="optionsLoading || accessLoading">{{ t('userSave') }}</UiButton></div>
        </form>
        </FormModal>
        <p class="text-sm text-slate-600">{{ t('userMembershipHelp') }}</p>
        <ConfirmDialog v-if="pending" v-model="confirming" danger :title="t(pending.kind === 'memberships' ? 'membershipRevoke' : 'rbacRevoke')" :message="`${t('userAccessRevokeConfirm')} ${accessLabel(pending)}`" :loading="saving" @confirm="revokeAccess" />
    </section>
</template>
