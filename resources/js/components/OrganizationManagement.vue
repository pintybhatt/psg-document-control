<script setup>
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import DataTable from './ui/DataTable.vue';
import UiButton from './ui/UiButton.vue';
import TextInput from './ui/TextInput.vue';
import CheckboxInput from './ui/CheckboxInput.vue';
import ConfirmDialog from './ui/ConfirmDialog.vue';
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
let controller;
let disposed = false;
const label = record => record?.[language.value === 'lo' ? 'name_lo' : 'name_en'] ?? '';
const columns = computed(() => [{ key: 'code', label: t('orgCode'), type: 'reference' }, { key: 'label', label: t('orgName') }, { key: 'status', label: t('uiStatus') }, { key: 'actions', label: t('rbacActions') }]);
const displayRows = computed(() => rows.value.map(row => ({ ...row, label: label(row), status: t(row.active ? 'rbacActive' : 'rbacInactive') })));
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
    const parameters = new URLSearchParams({ page: page.value, per_page: 10 });
    if (kind.value !== 'companies') { parameters.set('company_id', company.value.id); }
    if (kind.value === 'departments') { parameters.set('site_id', site.value.id); }
    try {
        const response = await request(`${base}/${kind.value}?${parameters}`, { signal });
        if (!signal.aborted) { rows.value = response.data; total.value = response.meta.total; }
    } catch (failure) { if (!signal.aborted) { handleError(failure); } }
    finally { if (!signal.aborted) { loading.value = false; } }
}
function navigate(target, parent = null) {
    if (saving.value) { return; }
    if (target === 'sites' && parent) { company.value = parent; site.value = null; }
    if (target === 'departments' && parent) { site.value = parent; }
    kind.value = target; page.value = 1; form.value = null; confirm.value = false; fields.value = {}; load();
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
load();
onBeforeUnmount(() => { disposed = true; controller?.abort(); });
</script>

<template>
    <section class="flex flex-col gap-4" aria-labelledby="org-heading">
        <h3 id="org-heading" class="font-semibold">{{ t('orgTitle') }}</h3>
        <p class="text-sm text-slate-600">{{ t('orgNotice') }}</p>
        <nav class="flex flex-wrap items-center gap-2" :aria-label="t('orgTitle')">
            <UiButton variant="secondary" :disabled="saving" @click="navigate('companies')">{{ t('orgCompanies') }}</UiButton>
            <UiButton v-if="company && kind !== 'companies'" variant="secondary" :disabled="saving" @click="navigate('sites')">{{ label(company) }} / {{ t('orgSites') }}</UiButton>
            <span v-if="site && kind === 'departments'" class="text-sm font-medium">{{ label(site) }} / {{ t('orgDepartments') }}</span>
        </nav>
        <p v-if="errorKey" role="alert" class="rounded-lg bg-red-50 p-3 text-sm text-red-800">{{ t(errorKey) }}</p>
        <div class="flex flex-wrap items-center justify-between gap-3"><h4 class="font-medium">{{ t(`org-${kind}`) }}</h4><UiButton :disabled="saving || loading" @click="edit()">{{ t('orgCreate') }}</UiButton></div>
        <DataTable v-model:page="page" :rows="displayRows" :columns="columns" :caption="t(`org-${kind}`)" :loading="loading" server-side :total="total" :per-page="10" @change="load">
            <template #cell-actions="{ row }"><div class="flex flex-wrap gap-2"><UiButton variant="secondary" :disabled="saving" @click="edit(row)">{{ t('rbacEdit') }}</UiButton><UiButton v-if="kind !== 'departments'" variant="secondary" :disabled="saving" @click="navigate(kind === 'companies' ? 'sites' : 'departments', row)">{{ t(kind === 'companies' ? 'orgSites' : 'orgDepartments') }}</UiButton></div></template>
        </DataTable>
        <form v-if="form" ref="editor" novalidate class="flex flex-col gap-4 rounded-xl border border-slate-200 bg-white p-5" @submit.prevent="prepareSave">
            <h4 class="font-semibold">{{ t(editing ? 'orgEdit' : 'orgCreate') }} · {{ t(`org-${kind}`) }}</h4>
            <p v-if="editing" class="text-sm text-slate-600">{{ t('orgIdentityNotice') }}</p>
            <div class="grid gap-4 sm:grid-cols-2">
                <TextInput v-model="form.code" name="org-code" :label="t('orgCode')" :readonly="!!editing" :disabled="saving" :error-key="fields.code?.[0]" required maxlength="32" />
                <TextInput v-model="form.name_en" name="org-name-en" :label="t('rbacNameEn')" :disabled="saving" :error-key="fields.name_en?.[0]" required lang="en" maxlength="255" />
                <TextInput v-model="form.name_lo" name="org-name-lo" :label="t('rbacNameLo')" :disabled="saving" :error-key="fields.name_lo?.[0]" required lang="lo" maxlength="255" />
                <CheckboxInput v-model="form.active" :label="t('rbacActive')" :disabled="saving" :error-key="fields.active?.[0]" />
            </div>
            <div class="flex flex-wrap gap-2"><UiButton type="submit" :loading="saving">{{ t('orgSave') }}</UiButton><UiButton variant="secondary" :disabled="saving" @click="form = null">{{ t('uiCancel') }}</UiButton></div>
        </form>
        <ConfirmDialog v-model="confirm" :danger="!form?.active" :title="t('orgStatusChange')" :message="`${label(editing)} · ${t(form?.active ? 'rbacActive' : 'rbacInactive')}. ${t('orgDeactivationNotice')}`" :loading="saving" @confirm="save" />
    </section>
</template>
