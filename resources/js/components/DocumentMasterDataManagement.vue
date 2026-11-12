<script setup>
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue';
import UiButton from './ui/UiButton.vue';
import IconButton from './ui/IconButton.vue';
import TextInput from './ui/TextInput.vue';
import TextareaInput from './ui/TextareaInput.vue';
import SelectInput from './ui/SelectInput.vue';
import CheckboxInput from './ui/CheckboxInput.vue';
import DataTable from './ui/DataTable.vue';
import ConfirmDialog from './ui/SweetConfirmDialog.vue';
import FormModal from './ui/FormModal.vue';
import { showSuccess } from '../utilities/alerts.js';
import { useLocalization } from '../localization/index.js';
import { createJsonClient } from '../utilities/jsonClient.js';

const props = defineProps({ scope: { type: Object, required: true }, endpoints: { type: Object, required: true }, csrfToken: { type: String, required: true } });
const emit = defineEmits(['unauthorized']);
const { t, language } = useLocalization();
const request = createJsonClient(props.csrfToken);
const kinds = ['categories', 'types', 'registers'];
const identities = ['incoming', 'outgoing', 'controlled'];
const lists = reactive(Object.fromEntries(kinds.map(kind => [kind, { rows: [], page: 1, total: 0, loading: false }])));
const classifications = ref([]);
const categoryOptions = ref([]);
const registerOptions = ref([]);
const errorKey = ref('');
const fields = ref({});
const saving = ref(false);
const optionsLoading = ref(false);
const revoked = ref(false);
const editor = ref(null);
const editorElement = ref(null);
const form = reactive({ code: '', name_en: '', name_lo: '', description_en: '', description_lo: '', active: true, category_id: '', register_key: 'incoming', register_ids: [], review_enabled: false, review_interval_months: '', review_baseline: '' });
const pending = ref(null);
const confirmationOpen = ref(false);
let controller;
const canManage = computed(() => !revoked.value && props.scope.permissions.includes('master-data.manage'));
const label = row => row?.[language.value === 'lo' ? 'name_lo' : 'name_en'] ?? '—';
const registerLabel = key => t(`masterRegister-${key}`);
const recordLabel = (kind, row) => kind === 'registers' ? registerLabel(row.register_key) : label(row);
const columns = kind => [
    { key: 'display_code', label: t('masterCode') }, { key: 'display_name', label: t('masterName') },
    ...(kind === 'types' ? [{ key: 'category_name', label: t('masterCategory') }, { key: 'register_names', label: t('masterRegisters') }] : []),
    { key: 'status', label: t('uiStatus') }, { key: 'actions', label: t('rbacActions') },
];
const displayRows = kind => lists[kind].rows.map(row => ({ ...row, display_code: row.code ?? row.register_key, display_name: recordLabel(kind, row), category_name: label(row.category), register_names: lists.registers.rows.filter(register => row.register_ids?.includes(register.id)).map(register => registerLabel(register.register_key)).join(', '), status: t(!row.active ? 'rbacInactive' : row.effective ? 'rbacActive' : 'masterBlocked') }));
const categories = computed(() => [{ value: '', label: t('uiChoose') }, ...categoryOptions.value.map(row => ({ value: row.id, label: label(row), disabled: !row.active }))]);
const registers = computed(() => identities.map(key => ({ value: key, label: registerLabel(key), disabled: lists.registers.rows.some(row => row.register_key === key) })));
const confirmationMessage = computed(() => pending.value ? `${t(pending.value.row.active ? 'masterDeactivateConfirm' : 'masterActivateConfirm')} ${recordLabel(pending.value.kind, pending.value.row)}` : '');
function scopeData() { return { scope_type: props.scope.scope_type, company_id: props.scope.company_id, site_id: props.scope.site_id }; }
function url(endpoint, page = 1, perPage = 10) {
    return `${endpoint}?${new URLSearchParams(Object.entries({ ...scopeData(), page, per_page: perPage }).filter(([, value]) => value !== null))}`;
}
function handleError(error) {
    if (error.name === 'AbortError') { return; }
    fields.value = error.fields ?? {};
    errorKey.value = Object.values(fields.value).flat().find(value => /^(rbac|master)/.test(value)) ?? error.message ?? 'rbacRequestFailed';
    if (!/^(rbac|master)/.test(errorKey.value)) { errorKey.value = 'rbacRequestFailed'; }
    if ([401, 403, 419].includes(error.status)) {
        revoked.value = true; controller?.abort(); editor.value = null; confirmationOpen.value = false; pending.value = null;
        for (const kind of kinds) { Object.assign(lists[kind], { rows: [], total: 0, loading: false }); }
        classifications.value = []; categoryOptions.value = []; registerOptions.value = [];
        emit('unauthorized', error);
    }
}
async function loadList(kind, signal = controller?.signal) {
    lists[kind].loading = true;
    try {
        const result = await request(url(props.endpoints[kind], lists[kind].page), { signal });
        if (!signal?.aborted) { lists[kind].rows = result.data; lists[kind].total = result.meta.total; }
    } catch (error) { if (!signal?.aborted) { handleError(error); } }
    finally { if (!signal?.aborted) { lists[kind].loading = false; } }
}
async function reset() {
    controller?.abort(); controller = new AbortController();
    const signal = controller.signal;
    revoked.value = false; editor.value = null; confirmationOpen.value = false; pending.value = null;
    errorKey.value = ''; fields.value = {}; classifications.value = []; categoryOptions.value = []; registerOptions.value = []; optionsLoading.value = false;
    for (const kind of kinds) { Object.assign(lists[kind], { rows: [], total: 0, page: 1 }); }
    await Promise.all(kinds.map(kind => loadList(kind, signal)));
    if (signal.aborted) { return; }
    try {
        const result = await request(url(props.endpoints.classifications), { signal });
        if (!signal.aborted) { classifications.value = result.data; }
    } catch (error) { if (!signal.aborted) { handleError(error); } }
}
async function allOptions(kind, signal) {
    const rows = [];
    let page = 1;
    let result;
    do {
        result = await request(url(props.endpoints[kind], page++, 50), { signal });
        if (signal.aborted) { return []; }
        rows.push(...result.data);
    } while (page <= result.meta.last_page);
    return rows;
}
async function openEditor(kind, row = null) {
    fields.value = {}; errorKey.value = ''; editor.value = { kind, id: row?.id ?? null };
    Object.assign(form, { code: row?.code ?? '', name_en: row?.name_en ?? '', name_lo: row?.name_lo ?? '', description_en: row?.description_en ?? '', description_lo: row?.description_lo ?? '', active: row?.active ?? true, category_id: row?.category_id ?? '', register_ids: [...(row?.register_ids ?? [])], review_enabled: row?.review_enabled ?? false, review_interval_months: row?.review_interval_months ?? '', review_baseline: row?.review_baseline ?? '', register_key: row?.register_key ?? identities.find(key => !lists.registers.rows.some(item => item.register_key === key)) ?? 'incoming' });
    const signal = controller.signal;
    if (kind === 'types') {
        optionsLoading.value = true;
        try {
            const [categoryRows, registerRows] = await Promise.all([allOptions('categories', signal), allOptions('registers', signal)]);
            if (signal.aborted) { return; }
            categoryOptions.value = categoryRows; registerOptions.value = registerRows;
        } catch (error) { if (!signal.aborted) { handleError(error); } }
        finally { if (!signal.aborted) { optionsLoading.value = false; } }
    }
    await nextTick();
    if (!signal.aborted) { (editorElement.value?.querySelector('input:not([readonly]):not(:disabled)') ?? editorElement.value?.querySelector('h4'))?.focus(); }
}
async function save() {
    if (!canManage.value || saving.value || optionsLoading.value || !editor.value) { return; }
    const currentScope = props.scope.scope_key;
    const { kind, id } = editor.value;
    const body = { ...scopeData(), active: form.active, description_en: form.description_en, description_lo: form.description_lo };
    if (kind === 'registers') { if (!id) { body.register_key = form.register_key; } }
    else {
        Object.assign(body, { name_en: form.name_en, name_lo: form.name_lo });
        if (!id) { body.code = form.code; }
        if (kind === 'types') {
            body.register_ids = [...form.register_ids];
            Object.assign(body, { review_enabled: form.review_enabled, review_interval_months: form.review_interval_months === '' ? null : Number(form.review_interval_months), review_baseline: form.review_baseline || null });
            if (!id) { body.category_id = form.category_id; }
        }
    }
    saving.value = true; errorKey.value = ''; fields.value = {};
    try {
        await request(`${props.endpoints[kind]}${id ? `/${id}` : ''}`, { method: id ? 'PATCH' : 'POST', body });
        if (props.scope.scope_key !== currentScope) { return; }
        editor.value = null; showSuccess(t('uiSaveSuccess'), t('masterSaved'));
        await Promise.all(kinds.map(item => loadList(item)));
    } catch (error) {
        if (props.scope.scope_key === currentScope) {
            handleError(error); await nextTick(); editorElement.value?.querySelector('[aria-invalid="true"]')?.focus();
        }
    } finally { saving.value = false; }
}
function prepareToggle(kind, row) { pending.value = { kind, row }; errorKey.value = ''; confirmationOpen.value = true; }
async function toggle() {
    if (!canManage.value || saving.value || !pending.value) { return; }
    const { kind, row } = pending.value;
    const currentScope = props.scope.scope_key;
    saving.value = true; errorKey.value = '';
    try {
        await request(`${props.endpoints[kind]}/${row.id}`, { method: 'PATCH', body: { ...scopeData(), active: !row.active } });
        if (props.scope.scope_key !== currentScope) { return; }
        confirmationOpen.value = false; editor.value = null; showSuccess(t('uiSaveSuccess'), t('masterSaved'));
        await Promise.all(kinds.map(item => loadList(item)));
    } catch (error) { if (props.scope.scope_key === currentScope) { confirmationOpen.value = false; handleError(error); } }
    finally { saving.value = false; }
}
function fieldError(name) { return fields.value[name]?.[0] ?? ''; }
watch(() => props.scope.scope_key, reset, { immediate: true });
onBeforeUnmount(() => controller?.abort());
</script>

<template>
    <section class="flex flex-col gap-6" aria-labelledby="master-heading">
        <div><h3 id="master-heading" class="text-lg font-semibold">{{ t('masterTitle') }}</h3><p class="mt-2 text-sm leading-7 text-slate-600">{{ t('masterScopeNotice') }}</p></div>
        <p v-if="errorKey && !editor" role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ t(errorKey) }}</p>
        <section v-for="kind in kinds" :key="kind" class="flex flex-col gap-4" :aria-labelledby="`master-${kind}`">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h4 :id="`master-${kind}`" class="font-semibold">{{ t(`master-${kind}`) }}</h4>
                <UiButton v-if="canManage && (kind !== 'registers' || lists.registers.total < 3)" :disabled="saving || lists[kind].loading || optionsLoading" @click="openEditor(kind)">{{ t('masterCreate') }} · {{ t(`master-${kind}`) }}</UiButton>
            </div>
            <DataTable v-model:page="lists[kind].page" :rows="displayRows(kind)" :columns="columns(kind)" :caption="t(`master-${kind}`)" :loading="lists[kind].loading" server-side :total="lists[kind].total" :per-page="10" @change="loadList(kind)">
                <template #cell-actions="{ row }"><div class="flex flex-wrap gap-2">
                    <IconButton :action="canManage ? 'edit' : 'view'" :label="t(canManage ? 'rbacEdit' : 'rbacDetails')" :disabled="saving || optionsLoading" @click="openEditor(kind, row)" />
                    <IconButton v-if="canManage" :action="row.active ? 'deactivate' : 'activate'" :label="t(row.active ? 'membershipSuspend' : 'membershipActivate')" :disabled="saving" @click="prepareToggle(kind, row)" />
                </div></template>
            </DataTable>
        </section>
        <FormModal v-if="editor" :title="`${t(`master-${editor.kind}`)} · ${t(editor.id ? 'rbacDetails' : 'masterCreate')}`" :subtitle="t('masterIdentityHelp')" :busy="saving" @close="editor = null">
        <form ref="editorElement" novalidate class="flex flex-col gap-5" @submit.prevent="save">
            <p v-if="errorKey" role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ t(errorKey) }}</p>
            <p v-if="optionsLoading" role="status">{{ t('uiLoading') }}</p>
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div v-if="editor.kind === 'registers'" class="sm:col-span-2"><SelectInput v-model="form.register_key" :label="t('masterRegister')" :options="registers" :disabled="!!editor.id || !canManage || saving" :error-key="fieldError('register_key')" required /></div>
                <template v-else>
                    <div :class="{ 'sm:col-span-2': editor.kind !== 'types' }"><TextInput v-model="form.code" :label="t('masterCode')" :helper="t('rbacCodeHelp')" :readonly="!!editor.id || !canManage" :error-key="fieldError('code')" maxlength="64" required /></div>
                    <SelectInput v-if="editor.kind === 'types'" v-model="form.category_id" :label="t('masterCategory')" :options="categories" :disabled="!!editor.id || !canManage || optionsLoading || saving" :error-key="fieldError('category_id')" required />
                    <TextInput v-model="form.name_en" :label="t('rbacNameEn')" :readonly="!canManage" :error-key="fieldError('name_en')" lang="en" maxlength="255" required />
                    <TextInput v-model="form.name_lo" :label="t('rbacNameLo')" :readonly="!canManage" :error-key="fieldError('name_lo')" lang="lo" maxlength="255" required />
                </template>
                <TextareaInput v-model="form.description_en" :label="t('masterDescriptionEn')" :readonly="!canManage" :error-key="fieldError('description_en')" lang="en" maxlength="2000" />
                <TextareaInput v-model="form.description_lo" :label="t('masterDescriptionLo')" :readonly="!canManage" :error-key="fieldError('description_lo')" lang="lo" maxlength="2000" />
                <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 sm:col-span-2"><CheckboxInput v-model="form.active" :label="t('rbacActive')" :disabled="!canManage || saving" :error-key="fieldError('active')" /></div>
            </div>
            <fieldset v-if="editor.kind === 'types'" :disabled="!canManage || saving || optionsLoading" class="rounded-xl border border-slate-200 p-4">
                <legend class="px-2 text-sm font-semibold">{{ t('masterApplicability') }}</legend>
                <p v-if="!optionsLoading && !registerOptions.length" class="text-sm text-slate-500">{{ t('masterConfigureRegisters') }}</p>
                <div class="grid grid-cols-1 gap-x-4 sm:grid-cols-3">
                    <label v-for="register in registerOptions" :key="register.id" class="mt-3 flex items-start gap-3 text-sm">
                        <input v-model="form.register_ids" type="checkbox" :value="register.id" :disabled="!register.active && !form.register_ids.includes(register.id)" class="mt-1 size-4 shrink-0 accent-teal-700" />
                        <span class="wrap-anywhere">{{ registerLabel(register.register_key) }} · {{ t(register.active ? 'rbacActive' : 'rbacInactive') }}</span>
                    </label>
                </div>
                <p v-if="fieldError('register_ids')" role="alert" class="mt-3 text-sm text-red-700">{{ t(fieldError('register_ids')) }}</p>
            </fieldset>
            <fieldset v-if="editor.kind === 'types'" :disabled="!canManage || saving" class="grid grid-cols-1 gap-4 rounded-xl border border-slate-200 p-4 sm:grid-cols-2">
                <legend class="px-2 text-sm font-semibold">{{ t('masterReviewPolicy') }}</legend>
                <p class="text-sm text-slate-600 sm:col-span-2">{{ t('masterReviewHelp') }}</p>
                <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 sm:col-span-2"><CheckboxInput id="master-review-enabled" v-model="form.review_enabled" :label="t('masterReviewEnabled')" :error-key="fieldError('review_enabled')" /></div>
                <TextInput id="master-review-months" v-model="form.review_interval_months" type="number" min="1" max="1200" :label="t('masterReviewMonths')" :error-key="fieldError('review_interval_months')" />
                <SelectInput id="master-review-baseline" v-model="form.review_baseline" :label="t('masterReviewBaseline')" :options="[{ value: '', label: t('uiChoose') }, { value: 'completion_date', label: t('masterReviewCompletionBaseline') }]" :error-key="fieldError('review_baseline')" />
            </fieldset>
            <div class="flex flex-wrap justify-end gap-3 border-t border-slate-200 pt-4"><UiButton variant="secondary" :disabled="saving" @click="editor = null">{{ t('uiCancel') }}</UiButton><UiButton v-if="canManage" type="submit" :loading="saving" :disabled="optionsLoading">{{ t('masterSave') }}</UiButton></div>
        </form>
        </FormModal>
        <section class="rounded-xl border border-slate-200 bg-white p-5" aria-labelledby="master-classifications">
            <h4 id="master-classifications" class="font-semibold">{{ t('masterClassifications') }}</h4>
            <p class="mt-2 text-sm leading-7 text-slate-600">{{ t('masterClassificationNotice') }}</p>
            <dl class="mt-4 grid gap-5 sm:grid-cols-3"><div v-for="level in classifications" :key="level.value"><dt class="text-sm font-semibold">{{ t(level.label_key) }}</dt><dd class="mt-2 text-sm leading-7 text-slate-600">{{ t(level.description_key) }}</dd></div></dl>
        </section>
        <ConfirmDialog v-model="confirmationOpen" :title="t('masterConfirmTitle')" :message="confirmationMessage" :danger="pending?.row.active" :loading="saving" @confirm="toggle" />
    </section>
</template>
