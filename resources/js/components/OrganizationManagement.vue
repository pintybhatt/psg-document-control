<script setup>
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import DataTable from './ui/DataTable.vue';
import StatusBadge from './ui/StatusBadge.vue';
import UiButton from './ui/UiButton.vue';
import IconButton from './ui/IconButton.vue';
import TextInput from './ui/TextInput.vue';
import ToggleSwitch from './ui/ToggleSwitch.vue';
import ConfirmDialog from './ui/SweetConfirmDialog.vue';
import FormModal from './ui/FormModal.vue';
import { useLocalization } from '../localization/index.js';
import { createJsonClient } from '../utilities/jsonClient.js';

const props = defineProps({ endpoint: { type: String, required: true }, csrfToken: { type: String, required: true } });
const emit = defineEmits(['unauthorized', 'saved']);
const { t, language } = useLocalization();
const request = createJsonClient(props.csrfToken);
const base = props.endpoint.replace(/\/companies$/, '');
const kind = ref('companies');
const company = ref(null);
const site = ref(null);
const search = ref('');
const appliedSearch = ref('');
const rows = ref([]);
const page = ref(1);
const total = ref(0);
const loading = ref(false);
const saving = ref(false);
const errorKey = ref('');
const fields = ref({});
const form = ref(null);
const editing = ref(null);
const editor = ref(null);
const confirm = ref(false);
const deleting = ref(null);
const confirmDelete = ref(false);
let controller;
let disposed = false;
const label = record => record?.[language.value === 'lo' ? 'name_lo' : 'name_en'] ?? '';
const columns = computed(() => [{ key: 'label', label: t('orgName') }, { key: 'code', label: t('orgCode'), type: 'reference' }, { key: 'status', label: t('uiStatus') }, { key: 'actions', label: t('rbacActions') }]);
const displayRows = computed(() => rows.value.map(row => ({ ...row, label: label(row), status_key: row.active ? 'active' : 'inactive', status: t(row.active ? 'rbacActive' : 'rbacInactive') })));
function handleError(failure) {
    if (disposed || failure.name === 'AbortError') { return; }
    fields.value = failure.fields ?? {};
    errorKey.value = Object.values(fields.value).flat().find(key => key.startsWith('org')) ?? 'rbacRequestFailed';
    if ([401, 403, 419].includes(failure.status)) { rows.value = []; form.value = null; confirm.value = false; emit('unauthorized', failure); }
}
async function load() {
    controller?.abort(); controller = new AbortController();
    const signal = controller.signal;
    rows.value = []; total.value = 0; loading.value = true; errorKey.value = '';
    const listing = kind.value;
    const term = appliedSearch.value;
    const parameters = new URLSearchParams({ page: term ? 1 : page.value, per_page: term ? 50 : 10 });
    if (listing !== 'companies') { parameters.set('company_id', company.value.id); }
    if (listing === 'departments') { parameters.set('site_id', site.value.id); }
    try {
        const matches = [];
        let response;
        do {
            response = await request(`${base}/${listing}?${parameters}`, { signal });
            if (signal.aborted) { return; }
            matches.push(...(term ? response.data.filter(record =>
                [record.code, record.name_en, record.name_lo].some(value => value?.toLowerCase().includes(term))) : response.data));
            parameters.set('page', Number(parameters.get('page')) + 1);
        } while (term && Number(parameters.get('page')) <= response.meta.last_page);
        rows.value = matches;
        total.value = term ? matches.length : response.meta.total;
    } catch (failure) { if (!signal.aborted) { handleError(failure); } }
    finally { if (!signal.aborted) { loading.value = false; } }
}
function searchRecords() {
    appliedSearch.value = search.value.trim().toLowerCase();
    page.value = 1;
    load();
}
function navigate(target, parent = null) {
    if (saving.value) { return; }
    if (target === 'companies') { company.value = null; site.value = null; }
    if (target === 'sites') { company.value = parent ?? company.value; site.value = null; }
    if (target === 'departments' && parent) { site.value = parent; }
    kind.value = target; form.value = null; confirm.value = false; fields.value = {};
    search.value = '';
    searchRecords();
}
async function edit(record = null) {
    editing.value = record;
    form.value = { code: record?.code ?? '', name_en: record?.name_en ?? '', name_lo: record?.name_lo ?? '', active: record?.active ?? false };
    fields.value = {}; errorKey.value = '';
    await nextTick(); editor.value?.querySelector('input:not([readonly])')?.focus();
}
async function prepareSave() {
    fields.value = {};
    for (const field of ['code', 'name_en', 'name_lo']) { if (!form.value[field].trim()) { fields.value[field] = ['orgRequired']; } }
    if (Object.keys(fields.value).length) { await nextTick(); editor.value?.querySelector('[aria-invalid="true"]')?.focus(); return; }
    if (editing.value && editing.value.active !== form.value.active) { confirm.value = true; return; }
    await save();
}
async function save() {
    if (saving.value) { return; }
    saving.value = true; errorKey.value = '';
    try {
        const data = { ...form.value };
        if (editing.value) { delete data.code; }
        else {
            if (kind.value !== 'companies') { data.company_id = company.value.id; }
            if (kind.value === 'departments') { data.site_id = site.value.id; }
        }
        await request(`${base}/${kind.value}${editing.value ? `/${editing.value.id}` : ''}`, { method: editing.value ? 'PATCH' : 'POST', body: data });
        if (disposed) { return; }
        form.value = null; confirm.value = false; emit('saved'); await load();
    } catch (failure) { confirm.value = false; handleError(failure); await nextTick(); editor.value?.querySelector('[aria-invalid="true"]')?.focus(); }
    finally { saving.value = false; }
}
function prepareDelete(record) {
    deleting.value = record; errorKey.value = ''; confirmDelete.value = true;
}
async function remove() {
    if (saving.value || !deleting.value) { return; }
    saving.value = true; errorKey.value = '';
    try {
        await request(`${base}/departments/${deleting.value.id}`, { method: 'DELETE' });
        if (disposed) { return; }
        confirmDelete.value = false; deleting.value = null; emit('saved'); await load();
    } catch (failure) { confirmDelete.value = false; handleError(failure); }
    finally { saving.value = false; }
}
load();
onBeforeUnmount(() => { disposed = true; controller?.abort(); });
</script>

<template>
    <section class="flex flex-col gap-4" aria-labelledby="org-heading">
        <p v-if="errorKey && !form" role="alert" class="rounded-lg bg-red-50 p-3 text-sm text-red-800">{{ t(errorKey) }}</p>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <nav v-if="kind !== 'companies'" class="mb-1 flex flex-wrap items-center gap-x-2 text-sm text-slate-600" :aria-label="t('orgTitle')">
                    <button type="button" :disabled="saving" class="min-h-8 rounded text-teal-800 underline" @click="navigate('companies')">{{ t('orgCompanies') }}</button>
                    <span aria-hidden="true">/</span>
                    <button v-if="kind === 'departments'" type="button" :disabled="saving" class="min-h-8 rounded text-left text-teal-800 underline" @click="navigate('sites')">{{ label(company) }}</button>
                    <span v-else>{{ label(company) }}</span>
                    <template v-if="kind === 'departments'"><span aria-hidden="true">/</span><span>{{ label(site) }}</span></template>
                </nav>
                <h3 id="org-heading" class="text-lg font-semibold">{{ t(`org-${kind}`) }}</h3>
                <p class="mt-1 text-sm text-slate-600">{{ t(`orgHelp-${kind}`) }}</p>
            </div>
            <UiButton :disabled="saving || loading" @click="edit()">{{ t(`orgAdd-${kind}`) }}</UiButton>
        </div>
        <form class="flex flex-wrap items-end gap-2" @submit.prevent="searchRecords">
            <TextInput v-model="search" type="search" :label="t(`orgSearch-${kind}`)" :placeholder="t('orgSearchPlaceholder')" />
            <UiButton type="submit" variant="secondary" :loading="loading">{{ t('userFind') }}</UiButton>
        </form>
        <DataTable v-model:page="page" :rows="displayRows" :columns="columns" :caption="t(`org-${kind}`)" :loading="loading" :server-side="!appliedSearch" :total="total" :per-page="10" @change="!appliedSearch && load()">
            <template #cell-status="{ row }"><StatusBadge :status="row.status_key" :label="row.status" /></template>
            <template #cell-actions="{ row }"><div class="flex flex-wrap gap-2"><IconButton action="edit" :label="t('rbacEdit')" :disabled="saving" @click="edit(row)" /><IconButton v-if="kind === 'departments'" action="delete" :label="t('orgDelete')" :disabled="saving" @click="prepareDelete(row)" /><UiButton v-if="kind !== 'departments'" variant="secondary" :disabled="saving" @click="navigate(kind === 'companies' ? 'sites' : 'departments', row)">{{ t(kind === 'companies' ? 'orgSites' : 'orgDepartments') }}</UiButton></div></template>
        </DataTable>
        <FormModal v-if="form" size="md" :title="t(`org${editing ? 'Edit' : 'Add'}-${kind}`)" :subtitle="editing ? t('orgIdentityNotice') : [label(company), label(site)].filter(Boolean).join(' / ')" :busy="saving" @close="form = null">
        <form ref="editor" novalidate class="flex flex-col gap-4" @submit.prevent="prepareSave">
            <p v-if="errorKey" role="alert" class="text-sm text-red-700">{{ t(errorKey) }}</p>
            <div class="grid grid-cols-1 gap-4">
                <TextInput v-model="form.code" name="org-code" :label="t('orgCode')" :readonly="!!editing" :disabled="saving" :error-key="fields.code?.[0]" required maxlength="32" />
                <TextInput v-model="form.name_en" name="org-name-en" :label="t('rbacNameEn')" :disabled="saving" :error-key="fields.name_en?.[0]" required lang="en" maxlength="255" />
                <TextInput v-model="form.name_lo" name="org-name-lo" :label="t('rbacNameLo')" :disabled="saving" :error-key="fields.name_lo?.[0]" required lang="lo" maxlength="255" />
                <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3"><ToggleSwitch v-model="form.active" :label="t(form.active ? 'rbacActive' : 'rbacInactive')" :disabled="saving" :error-key="fields.active?.[0]" /></div>
            </div>
            <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 pt-4"><UiButton variant="secondary" :disabled="saving" @click="form = null">{{ t('uiCancel') }}</UiButton><UiButton type="submit" :loading="saving">{{ t('orgSave') }}</UiButton></div>
        </form>
        </FormModal>
        <ConfirmDialog v-model="confirm" :danger="!form?.active" :title="t('orgStatusChange')" :message="`${label(editing)} · ${t(form?.active ? 'rbacActive' : 'rbacInactive')}. ${t('orgDeactivationNotice')}`" :loading="saving" @confirm="save" />
        <ConfirmDialog v-model="confirmDelete" danger :title="t('orgDeleteTitle')" :message="`${deleting?.code ?? ''} · ${label(deleting)}. ${t('orgDeleteNotice')}`" :loading="saving" @confirm="remove" />
    </section>
</template>
