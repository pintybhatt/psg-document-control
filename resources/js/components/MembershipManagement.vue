<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import UiButton from './ui/UiButton.vue';
import IconButton from './ui/IconButton.vue';
import TextInput from './ui/TextInput.vue';
import SelectInput from './ui/SelectInput.vue';
import CheckboxInput from './ui/CheckboxInput.vue';
import DataTable from './ui/DataTable.vue';
import StatusBadge from './ui/StatusBadge.vue';
import ConfirmDialog from './ui/SweetConfirmDialog.vue';
import FormModal from './ui/FormModal.vue';
import { showSuccess } from '../utilities/alerts.js';
import { useLocalization } from '../localization/index.js';
import { createJsonClient } from '../utilities/jsonClient.js';

const props = defineProps({ scope: { type: Object, required: true }, endpoints: { type: Object, required: true }, csrfToken: { type: String, required: true } });
const emit = defineEmits(['unauthorized']);
const { t, language } = useLocalization();
const request = createJsonClient(props.csrfToken);
const authorityRevoked = ref(false);
const can = key => !authorityRevoked.value && props.scope.permissions.includes(key);
const rows = ref([]);
const page = ref(1);
const total = ref(0);
const loading = ref(false);
const saving = ref(false);
const searching = ref(false);
const errorKey = ref('');
const userSearch = ref('');
const unitSearch = ref('');
const unitType = ref('site');
const users = ref([]);
const units = ref([]);
const selectedUser = ref('');
const selectedUnit = ref('');
const confirmRegrant = ref(false);
const pending = ref(null);
const confirmationOpen = ref(false);
const assignOpen = ref(false);
let controller;
let candidateController;
const label = record => record?.[language.value === 'lo' ? 'name_lo' : 'name_en'] ?? '—';
const unitLabel = unit => unit.unit_type === 'company' ? label(unit) : `${label(unit.company)}${unit.site ? ` / ${label(unit.site)}` : ''} / ${label(unit)}`;
const userOptions = computed(() => [{ value: '', label: t('uiChoose') }, ...users.value.map(user => ({ value: user.id, label: `${user.name} (${user.email})` }))]);
const unitOptions = computed(() => [{ value: '', label: t('uiChoose') }, ...units.value.map(unit => ({ value: unit.id, label: unitLabel(unit) }))]);
const typeOptions = computed(() => ['company', 'site', 'department'].filter(type => props.scope.scope_type !== 'site' || type !== 'company').map(type => ({ value: type, label: t(`membershipType-${type}`) })));
const displayRows = computed(() => rows.value.map(row => ({ ...row, account: row.user.name, company_name: label(row.company), site_name: label(row.site), department_name: label(row.department), scope_name: t(`membershipType-${row.membership_type}`) })));
const columns = computed(() => [
    { key: 'account', label: t('rbacAccount') }, { key: 'company_name', label: t('company') }, { key: 'site_name', label: t('site') },
    { key: 'department_name', label: t('membershipDepartment') }, { key: 'scope_name', label: t('membershipScope') },
    { key: 'status', label: t('membershipEffectiveStatus') }, { key: 'actions', label: t('rbacActions') },
]);
const confirmationMessage = computed(() => {
    const action = pending.value;
    if (!action) { return ''; }
    return `${t(`membershipConfirm-${action.kind}`)} ${action.account} · ${t(`membershipType-${action.type}`)} · ${action.company}${action.site ? ` / ${action.site}` : ''}${action.department ? ` / ${action.department}` : ''}`;
});
function scopeData() { return { scope_type: props.scope.scope_type, company_id: props.scope.company_id, site_id: props.scope.site_id }; }
function url(endpoint, extra = {}) {
    return `${endpoint}?${new URLSearchParams(Object.entries({ ...scopeData(), ...extra }).filter(([, value]) => value !== null && value !== ''))}`;
}
function handleError(error) {
    if (error.name === 'AbortError') { return; }
    const known = Object.values(error.fields ?? {}).flat().find(message => /^(membership|rbac)/.test(message));
    errorKey.value = known ?? (/^(membership|rbac)/.test(error.message) ? error.message : 'rbacRequestFailed');
    if ([401, 403, 419].includes(error.status)) {
        controller?.abort(); candidateController?.abort();
        authorityRevoked.value = true; loading.value = false; searching.value = false;
        rows.value = []; users.value = []; units.value = []; assignOpen.value = false;
        confirmationOpen.value = false;
        emit('unauthorized', error);
    }
}
async function loadRows() {
    const signal = controller?.signal;
    loading.value = true;
    try {
        const response = await request(url(props.endpoints.memberships, { page: page.value, per_page: 10 }), { signal });
        if (signal?.aborted) { return; }
        rows.value = response.data; total.value = response.meta.total;
    } catch (error) { if (!signal?.aborted) { handleError(error); } }
    finally { if (!signal?.aborted) { loading.value = false; } }
}
function clearCandidates() {
    users.value = []; units.value = []; selectedUser.value = ''; selectedUnit.value = ''; confirmRegrant.value = false;
}
async function reset() {
    controller?.abort(); candidateController?.abort(); controller = new AbortController();
    authorityRevoked.value = false;
    if (props.scope.scope_type === 'site' && unitType.value === 'company') { unitType.value = 'site'; }
    page.value = 1; total.value = 0; rows.value = []; pending.value = null; confirmationOpen.value = false; assignOpen.value = false;
    errorKey.value = ''; userSearch.value = ''; unitSearch.value = ''; searching.value = false;
    clearCandidates();
    await loadRows();
}
async function searchCandidates() {
    candidateController?.abort(); candidateController = new AbortController();
    const signal = candidateController.signal;
    searching.value = true; errorKey.value = ''; clearCandidates();
    try {
        const [accounts, organizations] = await Promise.all([
            request(url(props.endpoints.membershipUsers, { q: userSearch.value, per_page: 50 }), { signal }),
            request(url(props.endpoints.membershipUnits, { unit_type: unitType.value, q: unitSearch.value, per_page: 50 }), { signal }),
        ]);
        if (signal?.aborted) { return; }
        users.value = accounts.data; units.value = organizations.data;
    } catch (error) { if (!signal.aborted) { handleError(error); } }
    finally { if (!signal?.aborted) { searching.value = false; } }
}
function prepareAssignment() {
    const user = users.value.find(user => user.id === Number(selectedUser.value));
    const unit = units.value.find(unit => unit.id === Number(selectedUnit.value));
    if (!user || !unit) { return; }
    pending.value = { kind: 'assign', account: `${user.name} (${user.email})`, type: unit.unit_type, company: label(unit.company), site: unit.unit_type === 'site' ? label(unit) : unit.site ? label(unit.site) : '', department: unit.unit_type === 'department' ? label(unit) : '', user_id: user.id, unit, confirm_regrant: confirmRegrant.value };
    confirmationOpen.value = true;
}
function prepareChange(row, kind) {
    pending.value = { kind, id: row.id, account: `${row.user.name} (${row.user.email})`, type: row.membership_type, company: label(row.company), site: row.site ? label(row.site) : '', department: row.department ? label(row.department) : '', active: !row.active };
    confirmationOpen.value = true;
}
async function confirmAction() {
    const action = pending.value;
    if (!action || saving.value) { return; }
    const originalScope = props.scope.scope_key;
    saving.value = true; errorKey.value = '';
    try {
        if (action.kind === 'assign') {
            const unit = action.unit;
            await request(props.endpoints.memberships, { method: 'POST', body: { ...scopeData(), user_id: action.user_id, unit_type: unit.unit_type, unit_company_id: unit.unit_company_id, unit_site_id: unit.unit_site_id, unit_department_id: unit.unit_department_id, confirm_regrant: action.confirm_regrant } });
        } else {
            await request(`${props.endpoints.memberships}/${action.id}`, { method: action.kind === 'revoke' ? 'DELETE' : 'PATCH', body: { ...scopeData(), ...(action.kind === 'revoke' ? {} : { active: action.active }) } });
        }
        if (props.scope.scope_key !== originalScope) { return; }
        confirmationOpen.value = false;
        if (action.kind === 'assign') { assignOpen.value = false; }
        showSuccess(t('uiSaveSuccess'), t('membershipSaved'));
        clearCandidates();
        await loadRows();
    } catch (error) { if (props.scope.scope_key === originalScope) { confirmationOpen.value = false; handleError(error); } }
    finally { saving.value = false; }
}
watch(() => props.scope.scope_key, reset, { immediate: true });
watch(unitType, () => { candidateController?.abort(); searching.value = false; clearCandidates(); });
onBeforeUnmount(() => { controller?.abort(); candidateController?.abort(); });
</script>

<template>
    <section class="flex flex-col gap-5" aria-labelledby="memberships-heading">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0 flex-1"><h3 id="memberships-heading" class="text-lg font-semibold">{{ t('membershipTitle') }}</h3><p class="mt-2 text-sm leading-7 text-slate-600">{{ t('membershipBoundary') }}</p></div>
            <UiButton v-if="can('memberships.manage') && can('users.view')" data-open-assign :disabled="saving || loading" @click="errorKey = ''; assignOpen = true">{{ t('membershipAssign') }}</UiButton>
        </div>
        <p v-if="errorKey && !assignOpen" role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ t(errorKey) }}</p>
        <DataTable v-model:page="page" :rows="displayRows" :columns="columns" :caption="t('membershipTitle')" :loading="loading" server-side :total="total" :per-page="10" @change="loadRows">
            <template #cell-status="{ row }"><StatusBadge :status="row.status" /></template>
            <template #cell-actions="{ row }">
                <div v-if="can('memberships.manage') && row.manageable" class="flex gap-2">
                    <IconButton v-if="row.active || row.activatable" :action="row.active ? 'deactivate' : 'activate'" :label="t(row.active ? 'membershipSuspend' : 'membershipActivate')" :disabled="saving" @click="prepareChange(row, row.active ? 'suspend' : 'activate')" />
                    <IconButton action="delete" :label="t('membershipRevoke')" :disabled="saving" @click="prepareChange(row, 'revoke')" />
                </div>
            </template>
        </DataTable>
        <FormModal v-if="assignOpen && can('memberships.manage') && can('users.view')" :title="t('membershipAssign')" :subtitle="t('membershipEligibility')" :busy="saving" @close="assignOpen = false">
        <form class="flex flex-col gap-5" @submit.prevent="prepareAssignment">
            <p v-if="errorKey" role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ t(errorKey) }}</p>
            <div class="grid grid-cols-1 items-end gap-4 rounded-xl border border-slate-200 bg-slate-50 p-4 sm:grid-cols-3">
                <SelectInput v-model="unitType" :label="t('membershipScope')" :options="typeOptions" :disabled="saving || confirmationOpen" />
                <TextInput v-model="unitSearch" :label="t('membershipUnitSearch')" maxlength="100" :disabled="saving" />
                <TextInput v-model="userSearch" :label="t('rbacAccountSearch')" maxlength="100" :disabled="saving" />
                <div class="flex flex-wrap items-center justify-between gap-3 sm:col-span-3">
                    <p class="text-xs text-slate-500">{{ t('membershipSearchNotice') }}</p>
                    <UiButton variant="secondary" :loading="searching" :disabled="saving || loading" @click="searchCandidates">{{ t('membershipFind') }}</UiButton>
                </div>
            </div>
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <SelectInput v-model="selectedUser" :label="t('rbacAccount')" :options="userOptions" :disabled="saving || searching" />
                <SelectInput v-model="selectedUnit" :label="t('membershipUnit')" :options="unitOptions" :disabled="saving || searching" />
            </div>
            <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3"><CheckboxInput v-model="confirmRegrant" :label="t('membershipRegrant')" :disabled="saving" /></div>
            <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 pt-4"><UiButton variant="secondary" :disabled="saving" @click="assignOpen = false">{{ t('uiCancel') }}</UiButton><UiButton type="submit" :disabled="saving || loading || searching || !selectedUser || !selectedUnit">{{ t('membershipAssign') }}</UiButton></div>
        </form>
        </FormModal>
        <ConfirmDialog v-model="confirmationOpen" :title="t('membershipConfirmTitle')" :message="confirmationMessage" :danger="pending?.kind === 'revoke' || pending?.kind === 'suspend'" :loading="saving" @confirm="confirmAction" />
    </section>
</template>
