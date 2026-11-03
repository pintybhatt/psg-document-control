<script setup>
import DocumentStorage from './DocumentStorage.vue';
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { formatDate, formatDateTime } from '../utilities/dates.js';
import { createJsonClient } from '../utilities/jsonClient.js';
import { useLocalization } from '../localization/index.js';
import TextInput from './ui/TextInput.vue';
import TextareaInput from './ui/TextareaInput.vue';
import SelectInput from './ui/SelectInput.vue';
import UiButton from './ui/UiButton.vue';
import StatusBadge from './ui/StatusBadge.vue';
import DataTable from './ui/DataTable.vue';
import ToastNotifications from './ui/ToastNotifications.vue';
import { useNotifications } from './ui/useNotifications.js';

const props = defineProps({
    scope: { type: Object, required: true },
    contextKey: { type: String, default: '' },
    departmentOptions: { type: Array, default: () => [] },
    endpoint: { type: String, required: true },
    csrfToken: { type: String, required: true },
});
const emit = defineEmits(['unauthorized', 'update:contextKey']);
const { t, language } = useLocalization();
const request = createJsonClient(props.csrfToken);
const { items, notify, dismiss } = useNotifications();
const rows = ref([]), types = ref([]), people = ref([]), total = ref(0), page = ref(1);
const defaultCustodianId = ref('');
const automaticCompany = ref(false);
const search = ref(''), status = ref(''), typeFilter = ref('');
const namespaceId = ref('');
const namespaceOptions = computed(() => [{ value: '', label: t('numberingLegacy') }, ...(detail.value?.xppl_namespaces ?? []).map(item => ({ value: item.id, label: item.prefix }))]);
const detail = ref(null), audits = ref([]), related = ref([]), editing = ref(false), action = ref('');
const loading = ref(false), saving = ref(false), error = ref(''), fields = ref({});
const replacements = ref([]), replacementSearch = ref('');
const actionForm = reactive({ reason: '', revision_reason: '', change_description: '', status: 'obsolete', replaced_by_id: '' });
const form = reactive({});
const editor = ref(null);
const selectedFile = ref(null), attachmentReason = ref(''), pendingUpload = ref(false), uploadError = ref('');
const attachmentRevisionId = ref(null), attachmentBusy = ref(false);
const attachmentRevision = computed(() => detail.value?.revisions.find(item => item.id === attachmentRevisionId.value) ?? draft.value ?? authoritative.value ?? detail.value?.revisions.at(-1));
const revisionLabel = revision => `R${revision?.identifier ?? '00'}`;
function clearSelectedFile() { selectedFile.value = null; attachmentReason.value = ''; pendingUpload.value = false; uploadError.value = ''; }
function storageUrl(record, revisionId) {
    return `${record.storage_url.replace(/\/$/, '')}/attachments?${new URLSearchParams(Object.entries({ ...scopeData(), revision_id: revisionId }).filter(([, value]) => value != null))}`;
}

let controller;
let reopenCreateAfterScopeChange = false;
const contextLoading = ref(false);
const contextValid = ref(true);
const textFields = ['title_en', 'title_lo'];
const dateFields = ['issue_date', 'effective_date', 'review_date'];
const detailFields = [...textFields, 'description_en', 'description_lo', 'owner_name', 'custodian_name', ...dateFields, 'revision_reason', 'change_description'];
const statuses = ['draft', 'current', 'superseded', 'obsolete', 'archived'];
const label = item => item?.[language.value === 'lo' ? 'name_lo' : 'name_en'] || item?.name_en || '';
const can = permission => props.scope.permissions.includes(permission);
const canRegister = computed(() => can('controlled.register') && can('numbering.issue'));
const draft = computed(() => detail.value?.revisions.find(item => item.status === 'draft'));
const authoritative = computed(() => detail.value?.revisions.find(item => item.id === detail.value.authoritative_revision_id));
const editable = computed(() => ['draft', 'current'].includes(detail.value?.status));
const typeOptions = computed(() => [{ value: '', label: t('uiChoose') }, ...types.value.map(item => ({ value: item.id, label: label(item) }))]);
const selectedType = computed(() => types.value.find(item => String(item.id) === String(form.type_id)));
const automaticDraft = computed(() => (!detail.value || !detail.value.document_code)
    && (automaticCompany.value || types.value.some(item => item.automatic_numbering != null)));
const numberPreview = computed(() => selectedType.value?.automatic_numbering);
const selectedCategoryLabel = computed(() => label(selectedType.value?.category));
const scopeDepartmentLabel = computed(() => label(props.scope.department) || t('siteWideDepartment'));
const authorizedDepartmentOptions = computed(() => props.departmentOptions.length
    ? props.departmentOptions
    : [{ value: props.scope.key, label: scopeDepartmentLabel.value }]);
const departmentContext = computed({
    get: () => props.contextKey || props.scope.key,
    set: value => {
        if (value === (props.contextKey || props.scope.key)) { return; }
        reopenCreateAfterScopeChange = editing.value && !detail.value;
        if (reopenCreateAfterScopeChange) { contextLoading.value = true; contextValid.value = false; }
        emit('update:contextKey', value);
    },
});
const peopleOptions = computed(() => [{ value: '', label: t('uiChoose') }, ...people.value.map(item => ({ value: item.id, label: item.name }))]);
const statusOptions = computed(() => [{ value: '', label: t('controlledAllStatuses') }, ...statuses.map(value => ({ value, label: t(`controlledStatus-${value}`) }))]);
const terminalOptions = computed(() => (detail.value?.status === 'current' ? ['obsolete', 'superseded'] : ['archived']).map(value => ({ value, label: t(`controlledStatus-${value}`) })));
const classificationOptions = computed(() => ['normal', 'confidential', 'highly_confidential'].map(value => ({ value, label: t(`confidentiality-${value}`) })));
const replacementOptions = computed(() => [{ value: '', label: t('uiChoose') }, ...replacements.value.filter(item => item.id !== detail.value?.id).map(item => ({ value: item.id, label: item.document_code }))]);
const columns = computed(() => [{ key: 'document_code', label: t('controlled-document_code') }, { key: 'title', label: t('controlledTitleLabel') }, { key: 'revision', label: t('controlledRevision') }, { key: 'status', label: t('uiStatus') }, { key: 'actions', label: t('rbacActions') }]);
const displayRows = computed(() => rows.value.map(item => { const revision = item.revisions.find(revision => revision.id === item.authoritative_revision_id) ?? item.revisions.at(-1); return { ...item, title: label(revision?.title_en ? { name_en: revision.title_en, name_lo: revision.title_lo } : null), display_identifier: revision?.full_identifier || item.document_code, revision: revision?.identifier ?? '—' }; }));
function scopeData() { return { company_id: props.scope.company_id, site_id: props.scope.site_id, department_id: props.scope.department_id }; }
function url(path = '', extra = {}) { return `${props.endpoint}${path}?${new URLSearchParams(Object.entries({ ...scopeData(), ...extra }).filter(([, value]) => value != null && value !== ''))}`; }
function fail(failure) {
    if (failure.name === 'AbortError') { return; }
    fields.value = failure.fields ?? {};
    error.value = Object.values(fields.value).flat().find(value => /^(controlled|rbac|numbering|storage)/.test(value)) ?? failure.message;
    if (!/^(controlled|rbac|numbering|storage)/.test(error.value)) { error.value = 'rbacRequestFailed'; }
    if ([401, 403, 404, 419].includes(failure.status)) {
        clearSelectedFile(); rows.value = []; detail.value = null; audits.value = []; related.value = []; types.value = []; people.value = []; replacements.value = []; total.value = 0; editing.value = false; action.value = '';
        if (failure.status !== 404) { controller?.abort(); emit('unauthorized', failure); }
    }
}
async function load(signal = controller.signal) {
    loading.value = true;
    try { const result = await request(url('', { page: page.value, search: search.value, status: status.value, type_id: typeFilter.value }), { signal }); if (!signal.aborted) { rows.value = result.data; total.value = result.meta.total; } }
    catch (failure) { if (!signal.aborted) { rows.value = []; total.value = 0; fail(failure); } }
    finally { if (!signal.aborted) { loading.value = false; } }
}
async function refreshCreateContext() {
    controller?.abort(); controller = new AbortController(); const signal = controller.signal;
    contextLoading.value = true; contextValid.value = false; saving.value = false; error.value = ''; fields.value = {};
    rows.value = []; total.value = 0; types.value = [];
    form.type_id = ''; form.classification = 'normal';
    people.value = []; form.owner_id = ''; form.custodian_id = ''; form.document_code = '';
    try {
        const result = await request(url('/lookups'), { signal });
        if (signal.aborted) { return; }
        types.value = result.data;
        automaticCompany.value = result.automatic_numbering ?? false;
        contextValid.value = can('controlled.drafts.manage');
        people.value = result.people;
        defaultCustodianId.value = result.people.some(person => person.id === result.default_custodian_id) ? result.default_custodian_id : '';
        form.custodian_id = defaultCustodianId.value;
    } catch (failure) { if (!signal.aborted) { fail(failure); } }
    finally { if (!signal.aborted) { contextLoading.value = false; } }
}
async function reset() {
    const reopenCreate = reopenCreateAfterScopeChange;
    reopenCreateAfterScopeChange = false;
    if (reopenCreate) { await refreshCreateContext(); return; }
    clearSelectedFile();
    contextLoading.value = false;
    contextValid.value = true;
    controller?.abort(); controller = new AbortController(); const signal = controller.signal;
    rows.value = []; detail.value = null; audits.value = []; related.value = []; types.value = []; people.value = []; replacements.value = []; total.value = 0; page.value = 1; editing.value = false; action.value = ''; error.value = ''; fields.value = {}; saving.value = false;
    await load(signal);
    if (signal.aborted) { return; }
    try { const result = await request(url('/lookups'), { signal }); if (!signal.aborted) { types.value = result.data; automaticCompany.value = result.automatic_numbering ?? false; people.value = result.people; defaultCustodianId.value = result.people.some(person => person.id === result.default_custodian_id) ? result.default_custodian_id : '';
        const query = new URLSearchParams(window.location.search);
        if (/^[1-9][0-9]*$/.test(query.get('record') ?? '')) { await show({ id: query.get('record') }); }
    } }
    catch (failure) { if (!signal.aborted) { fail(failure); } }
}
async function show(row) {
    clearSelectedFile(); attachmentRevisionId.value = null;
    const signal = controller.signal;
    detail.value = null; audits.value = []; related.value = []; editing.value = false; action.value = ''; error.value = ''; fields.value = {}; loading.value = true;
    try { const result = await request(url(`/${row.id}`), { signal }); if (!signal.aborted) { detail.value = result.data; audits.value = result.audits; related.value = result.related; await nextTick(); document.getElementById('controlled-detail-title')?.focus(); } }
    catch (failure) { if (!signal.aborted) { fail(failure); } }
    finally { if (!signal.aborted) { loading.value = false; } }
}
async function back() { clearSelectedFile(); detail.value = null; audits.value = []; related.value = []; editing.value = false; action.value = ''; error.value = ''; await load(); }
async function edit(create = false) {
    clearSelectedFile();
    if (create) { detail.value = null; audits.value = []; related.value = []; }
    for (const key of [...textFields, ...dateFields, 'description_en', 'description_lo', 'owner_id', 'custodian_id', 'revision_reason', 'change_description']) { form[key] = draft.value?.[key] ?? ''; }
    Object.assign(form, { document_code: detail.value?.document_code ?? '', type_id: detail.value?.type_id ?? '', classification: 'normal' });
    if (create) { form.custodian_id = defaultCustodianId.value; }
    if (create) { form.revision_reason = 'Initial issue'; form.change_description = 'First issue of the document'; }
    editing.value = true; action.value = ''; fields.value = {}; error.value = '';
    await nextTick(); editor.value?.querySelector('h2')?.focus();
}
async function save() {
    if (contextLoading.value || !contextValid.value) { return; }
    if (saving.value || attachmentBusy.value) { return; }
    if (pendingUpload.value) { await uploadSavedDraft(); return; }
    const body = { ...form };
    body.owner_id = form.owner_id || null;
    if (automaticDraft.value) { delete body.document_code; }
    if (detail.value) { body.lock_version = detail.value.lock_version; delete body.classification; }
    if (detail.value?.registered_at) { delete body.document_code; delete body.type_id; }
    const signal = controller.signal;
    saving.value = true; fields.value = {}; error.value = '';
    try {
        const result = await request(url(detail.value ? `/${detail.value.id}` : ''), { method: detail.value ? 'PATCH' : 'POST', body: { ...scopeData(), ...body }, signal });
        if (signal.aborted) { return; }
        // Keep the saved identity before attempting the separate attachment request.
        detail.value = result.data;
        if (selectedFile.value) { pendingUpload.value = true; await uploadSavedDraft(); }
        else { await show(result.data); notify({ type: 'success', messageKey: 'controlledSaved' }); }
    } catch (failure) { if (!signal.aborted) { fail(failure); await nextTick(); editor.value?.querySelector('[aria-invalid="true"]')?.focus(); } }
    finally { if (!signal.aborted) { saving.value = false; } }
}
async function uploadSavedDraft() {
    if (!selectedFile.value || !detail.value || !draft.value) { return; }
    const signal = controller.signal;
    saving.value = true; uploadError.value = ''; fields.value = {};
    try {
        const body = new FormData();
        body.append('file', selectedFile.value);
        body.append('reason', attachmentReason.value.trim() || t('controlledInitialAttachment'));
        await request(storageUrl(detail.value, draft.value.id), { method: 'POST', body, signal });
        if (!signal.aborted) { const record = detail.value; await show(record); notify({ type: 'success', messageKey: 'controlledSavedWithAttachment' }); }
    } catch (failure) {
        if (!signal.aborted) {
            if ([401, 403, 404, 419].includes(failure.status)) { fail(failure); }
            else { uploadError.value = Object.values(failure.fields ?? {}).flat().find(value => /^(storage|rbac)/.test(value)) ?? 'controlledAttachmentFailed'; }
        }
    } finally { if (!signal.aborted) { saving.value = false; } }
}
async function cancelEdit() {
    if (pendingUpload.value) { await show(detail.value); }
    else { clearSelectedFile(); editing.value = false; }
}
async function mutate(path, body, method = 'POST') {
    const signal = controller.signal;
    saving.value = true; fields.value = {}; error.value = '';
    try { const result = await request(url(path), { method, body: { ...scopeData(), ...body }, signal }); if (!signal.aborted) { editing.value = false; action.value = ''; await show(result.data); notify({ type: 'success', messageKey: 'controlledSaved' }); } }
    catch (failure) { if (!signal.aborted) { fail(failure); await nextTick(); editor.value?.querySelector('[aria-invalid="true"]')?.focus(); } }
    finally { if (!signal.aborted) { saving.value = false; } }
}
async function beginAction(value) {
    action.value = value; error.value = ''; fields.value = {};
    Object.assign(actionForm, { reason: '', revision_reason: '', change_description: '', status: detail.value.status === 'current' ? 'obsolete' : 'archived', replaced_by_id: '' });
    replacements.value = []; replacementSearch.value = '';
    await nextTick(); document.getElementById('controlled-action-title')?.focus();
}
async function findReplacements() {
    const signal = controller.signal;
    loading.value = true;
    try { const result = await request(url('', { status: 'current', search: replacementSearch.value, per_page: 50 }), { signal }); if (!signal.aborted) { replacements.value = result.data; } }
    catch (failure) { if (!signal.aborted) { fail(failure); } }
    finally { if (!signal.aborted) { loading.value = false; } }
}
async function submitAction() {
    if (saving.value || attachmentBusy.value || !detail.value) { return; }
    const body = { lock_version: detail.value.lock_version };
    if (action.value === 'register' && namespaceId.value) { body.namespace_id = Number(namespaceId.value); }
    if (action.value === 'revisions') { Object.assign(body, { revision_reason: actionForm.revision_reason, change_description: actionForm.change_description }); }
    if (action.value === 'release') { Object.assign(body, { revision_id: draft.value.id, reason: actionForm.reason }); }
    if (action.value === 'status') { Object.assign(body, { status: actionForm.status, reason: actionForm.reason }); if (actionForm.status === 'superseded') { body.replaced_by_id = actionForm.replaced_by_id; } }
    await mutate(`/${detail.value.id}/${action.value}`, body);
}
watch(() => props.scope.key, reset, { immediate: true });
watch(() => detail.value?.id, () => { namespaceId.value = ''; });
onBeforeUnmount(() => controller?.abort());
</script>

<template>
    <section class="flex flex-col gap-5">
        <SelectInput v-if="!detail && !editing && authorizedDepartmentOptions.length > 1" id="controlled-context" v-model="departmentContext" :label="t('controlledOwnerDepartment')" :options="authorizedDepartmentOptions" :disabled="loading || saving" class="max-w-sm" />
        <div v-if="!detail && !editing" class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-lg font-semibold">{{ t('controlledTitle') }}</h2><UiButton v-if="can('controlled.drafts.manage')" :disabled="loading || saving" @click="edit(true)">{{ t('controlledCreate') }}</UiButton></div>
        <UiButton v-if="detail || editing" variant="secondary" class="self-start" :disabled="saving || loading || attachmentBusy" @click="back">{{ t('backToRegister') }}</UiButton>
        <p v-if="error" role="alert" class="rounded-xl bg-red-50 p-4 text-red-800">{{ t(error) }}</p>
        <template v-if="!detail && !editing">
            <form class="flex flex-wrap items-end gap-3" @submit.prevent="page = 1; error = ''; load()">
                <TextInput id="controlled-search" v-model="search" :label="t('controlledSearch')" maxlength="200" class="grow" />
                <SelectInput id="controlled-status-filter" v-model="status" :label="t('uiStatus')" :options="statusOptions" />
                <SelectInput id="controlled-type-filter" v-model="typeFilter" :label="t('documentType')" :options="typeOptions" />
                <UiButton type="submit" variant="secondary" :disabled="loading">{{ t('incomingApplyFilters') }}</UiButton>
            </form>
            <DataTable v-model:page="page" :rows="displayRows" :columns="columns" :caption="t('controlledTitle')" :loading="loading" :error="error" server-side :total="total" :per-page="10" @change="load()">
                <template #cell-document_code="{ row }"><button type="button" :disabled="loading" class="text-left font-semibold text-teal-800 hover:underline" @click="show(row)">{{ row.display_identifier || t('controlledUnnumbered') }}</button><span class="block font-mono text-xs text-slate-500">{{ row.internal_number || '—' }}</span></template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status" :label="t(`controlledStatus-${row.status}`)" /></template>
                <template #cell-actions="{ row }"><UiButton variant="secondary" :disabled="loading" @click="show(row)">{{ t('rbacDetails') }}</UiButton></template>
            </DataTable>
        </template>
        <form v-if="editing" id="controlled-draft-form" ref="editor" class="flex flex-col gap-5 rounded-2xl border border-slate-200 bg-white p-5" @submit.prevent="save">
            <h2 tabindex="-1" class="text-lg font-semibold">{{ t(detail ? 'controlledEdit' : 'controlledCreate') }}</h2>
            <p class="text-sm text-slate-600">{{ t('controlledDraftHelp') }}</p>
            <fieldset :disabled="saving || pendingUpload || attachmentBusy" class="grid gap-4 md:grid-cols-2"><legend class="mb-3 font-semibold">{{ t('documentInformation') }}</legend>
                <SelectInput id="controlled-department" v-model="departmentContext" :label="t('controlledOwnerDepartment')" :options="authorizedDepartmentOptions" :helper="t('documentDepartmentHelp')" :disabled="!!detail || saving" required />
                <template v-if="!detail?.registered_at"><TextInput v-if="!automaticDraft" id="controlled-document_code" v-model="form.document_code" :label="t('controlled-document_code')" :error-key="fields.document_code?.[0]" maxlength="100" required /><SelectInput id="controlled-type" v-model="form.type_id" :label="t('documentType')" :options="typeOptions" :error-key="fields.type_id?.[0]" required /></template>
                <template v-if="automaticDraft">
                    <TextInput id="controlled-number-preview" :model-value="numberPreview?.preview || ''" :label="t('controlledNumberPreview')" :helper="t('controlledNumberProvisional')" readonly />
                    <p v-if="!numberPreview?.ready" class="text-sm text-slate-600">{{ t('controlledAutomaticConfiguration') }}</p>
                </template>
                <TextInput v-if="detail?.registered_at" id="controlled-type" :model-value="label(detail.type)" :label="t('documentType')" readonly />
                <TextInput id="controlled-category" :model-value="selectedCategoryLabel" :label="t('documentCategory')" :helper="t('documentCategoryHelp')" readonly />
                <SelectInput v-if="!detail" id="controlled-classification" v-model="form.classification" :label="t('outgoingClassification')" :options="classificationOptions" :error-key="fields.classification?.[0]" required />
                <TextInput v-for="field in textFields" :id="`controlled-${field}`" :key="field" v-model="form[field]" :label="t(`controlled-${field}`)" :required="field === 'title_en'" :error-key="fields[field]?.[0]" maxlength="500" />
                <SelectInput v-for="field in ['owner_id', 'custodian_id']" :id="`controlled-${field}`" :key="field" v-model="form[field]" :label="t(`controlled-${field}`)" :options="peopleOptions" :error-key="fields[field]?.[0]" :required="field === 'custodian_id'" :helper="field === 'owner_id' ? t('controlledOwnerHelp') : t('controlledCustodianHelp')" />
                <p v-if="!contextLoading && !people.length" role="status" class="text-sm text-slate-600">{{ t('controlledNoCustodian') }}</p>
                <TextInput v-for="field in dateFields" :id="`controlled-${field}`" :key="field" v-model="form[field]" :label="t(`controlled-${field}`)" type="date" :required="field !== 'review_date'" :error-key="fields[field]?.[0]" />
                <TextareaInput v-for="field in ['revision_reason', 'change_description']" :id="`controlled-${field}`" :key="field" v-model="form[field]" :label="t(`controlled-${field}`)" :error-key="fields[field]?.[0]" maxlength="5000" required />
            </fieldset>
            <details class="rounded-xl border border-slate-200 p-4"><summary class="cursor-pointer text-sm font-semibold">{{ t('additionalInformation') }}</summary><div class="mt-4 grid gap-4 md:grid-cols-2"><TextareaInput v-for="field in ['description_en', 'description_lo']" :id="`controlled-${field}`" :key="field" v-model="form[field]" :label="t(`controlled-${field}`)" :error-key="fields[field]?.[0]" maxlength="5000" :disabled="saving || pendingUpload || attachmentBusy" /></div></details>
            <section v-if="!detail?.storage_url || pendingUpload" class="flex flex-col gap-3 rounded-xl border border-teal-300 bg-teal-50 p-4" aria-labelledby="controlled-attachment-title">
                <h3 id="controlled-attachment-title" class="font-semibold text-teal-900">{{ t('controlledAttachment') }} · {{ revisionLabel(draft) }}</h3>
                <p class="text-sm text-slate-700">{{ t('controlledAttachmentHelp') }}</p>
                <template v-if="can('documents.attachments.manage')">
                    <label class="flex flex-col gap-2 text-sm" for="controlled-attachment-file">{{ t('storageFile') }}<input id="controlled-attachment-file" type="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.webp,.tif,.tiff,.dwg,.dxf" :disabled="saving" class="ui-control max-w-full" @change="selectedFile = $event.target.files[0] ?? null" /></label>
                    <p v-if="selectedFile" class="text-sm wrap-anywhere">{{ selectedFile.name }}</p>
                    <TextInput v-if="selectedFile" id="controlled-attachment-reason" v-model="attachmentReason" :label="t('controlledAttachmentNote')" maxlength="2000" :disabled="saving" />
                </template>
                <p v-else class="text-sm text-slate-600">{{ t('controlledAttachmentPermission') }}</p>
                <p v-if="pendingUpload" role="status" class="text-sm font-semibold text-teal-900">{{ t('controlledDraftSavedUploadPending') }}</p>
                <p v-if="uploadError" role="alert" class="text-sm text-red-800">{{ t(uploadError) }}</p>
            </section>
            <template v-else-if="draft">
                <p v-if="authoritative" class="text-sm text-slate-600">{{ t('controlledReleasedAttachment', { revision: revisionLabel(authoritative) }) }} · {{ t('controlledDraftFileHelp') }}</p>
                <p class="text-sm font-semibold text-teal-900">{{ t('controlledDraftAttachment', { revision: revisionLabel(draft) }) }}</p>
                <DocumentStorage mode="attachments" embedded :fixed-revision="draft.id" :endpoint="detail.storage_url" :scope="scope" :csrf-token="csrfToken" :disabled="saving" @failure="fail" @busy="attachmentBusy = $event" />
            </template>
            <p v-if="contextLoading" role="status" class="text-sm text-slate-600">{{ t('documentDepartmentLoading') }}</p>
            <div class="flex gap-3"><UiButton type="submit" :loading="saving" :disabled="contextLoading || !contextValid || attachmentBusy">{{ t(pendingUpload ? 'controlledRetryAttachment' : 'controlledSave') }}</UiButton><UiButton variant="secondary" :disabled="saving || attachmentBusy" @click="cancelEdit">{{ t(pendingUpload ? 'controlledContinueWithoutAttachment' : 'uiCancel') }}</UiButton></div>
        </form>
        <section v-else-if="detail" class="flex flex-col gap-5 rounded-2xl border border-slate-200 bg-white p-5" aria-labelledby="controlled-detail-title">
            <div class="flex flex-wrap items-center justify-between gap-3"><h2 id="controlled-detail-title" tabindex="-1" class="text-lg font-semibold">{{ authoritative?.full_identifier || draft?.full_identifier || detail.document_code || t('controlledUnnumbered') }}</h2><StatusBadge :status="detail.status" :label="t(`controlledStatus-${detail.status}`)" /></div>
            <section v-if="detail.storage_url && attachmentRevision" class="flex flex-col gap-3">
                <div class="flex flex-wrap gap-2">
                    <UiButton v-for="revision in detail.revisions.toReversed()" :key="revision.id" variant="secondary" :disabled="saving || loading || attachmentBusy" :aria-pressed="attachmentRevision.id === revision.id" @click="attachmentRevisionId = revision.id">{{ t(revision.status === 'draft' ? 'controlledDraftAttachment' : revision.id === detail.authoritative_revision_id ? 'controlledReleasedAttachment' : 'controlledHistoryAttachment', { revision: revisionLabel(revision) }) }}</UiButton>
                </div>
                <p class="text-sm font-semibold text-teal-900">{{ t(attachmentRevision.status === 'draft' ? 'controlledDraftAttachment' : attachmentRevision.id === detail.authoritative_revision_id ? 'controlledReleasedAttachment' : 'controlledHistoryAttachment', { revision: revisionLabel(attachmentRevision) }) }}</p>
                <DocumentStorage mode="attachments" :fixed-revision="attachmentRevision.id" :endpoint="detail.storage_url" :scope="scope" :csrf-token="csrfToken" :disabled="saving" @failure="fail" @busy="attachmentBusy = $event" />
            </section>
            <dl class="grid gap-4 md:grid-cols-2">
                <div><dt class="text-sm text-slate-600">{{ t('controlledNumber') }}</dt><dd class="font-mono">{{ detail.internal_number || '—' }}</dd></div>
                <div><dt class="text-sm text-slate-600">{{ t('controlledOwnerDepartment') }}</dt><dd>{{ scopeDepartmentLabel }}</dd></div>
                <div><dt class="text-sm text-slate-600">{{ t('documentType') }}</dt><dd>{{ label(detail.type) }}</dd></div>
                <div><dt class="text-sm text-slate-600">{{ t('documentCategory') }}</dt><dd>{{ label(detail.category) }}</dd></div>
                <div><dt class="text-sm text-slate-600">{{ t('outgoingClassification') }}</dt><dd>{{ t(`confidentiality-${detail.classification}`) }}</dd></div>
                <div><dt class="text-sm text-slate-600">{{ t('controlledAuthoritative') }}</dt><dd class="font-semibold">{{ authoritative ? `${authoritative.full_identifier || authoritative.identifier} · ${language === 'lo' ? authoritative.title_lo || authoritative.title_en : authoritative.title_en}` : t('controlledNoAuthority') }}</dd></div>
            </dl>
            <div v-if="!action" class="flex flex-wrap gap-3">
                <UiButton v-if="draft && editable && can('controlled.drafts.manage')" variant="secondary" :disabled="saving || loading || attachmentBusy" @click="edit()">{{ t('controlledEdit') }}</UiButton>
                <SelectInput v-if="!detail.registered_at && detail.document_code && detail.xppl_namespaces?.length" v-model="namespaceId" :label="t('numberingSelection')" :options="namespaceOptions" :disabled="saving || loading || attachmentBusy" />
                <UiButton v-if="!detail.registered_at && canRegister" :disabled="saving || loading || attachmentBusy" @click="beginAction('register')">{{ t('controlledAction-register') }}</UiButton>
                <UiButton v-if="draft && editable && detail.registered_at && can('controlled.release')" :disabled="saving || loading || attachmentBusy" @click="beginAction('release')">{{ t('controlledAction-release') }}</UiButton>
                <UiButton v-if="!draft && detail.status === 'current' && can('controlled.drafts.manage')" variant="secondary" :disabled="saving || loading || attachmentBusy" @click="beginAction('revisions')">{{ t('controlledAction-revisions') }}</UiButton>
                <UiButton v-if="['current', 'obsolete', 'superseded'].includes(detail.status) && can('controlled.status.manage')" variant="secondary" :disabled="saving || loading || attachmentBusy" @click="beginAction('status')">{{ t('controlledAction-status') }}</UiButton>
            </div>
            <form v-if="action" id="controlled-action-form" class="flex flex-col gap-4 rounded-xl border border-teal-200 bg-teal-50 p-4" @submit.prevent="submitAction">
                <h3 id="controlled-action-title" tabindex="-1" class="font-semibold">{{ t(`controlledAction-${action}`) }}</h3>
                <p class="text-sm text-slate-700">{{ t(`controlledConfirm-${action}`) }}</p>
                <fieldset :disabled="saving" class="grid gap-4 md:grid-cols-2">
                    <template v-if="action === 'revisions'"><TextareaInput v-for="field in ['revision_reason', 'change_description']" :id="`controlled-action-${field}`" :key="field" v-model="actionForm[field]" :label="t(`controlled-${field}`)" :error-key="fields[field]?.[0]" maxlength="5000" required /></template>
                    <TextareaInput v-if="['release', 'status'].includes(action)" id="controlled-action-reason" v-model="actionForm.reason" :label="t('controlledReason')" :error-key="fields.reason?.[0]" maxlength="5000" required />
                    <SelectInput v-if="action === 'status'" id="controlled-next-status" v-model="actionForm.status" :label="t('uiStatus')" :options="terminalOptions" required />
                    <template v-if="action === 'status' && actionForm.status === 'superseded'"><TextInput id="controlled-replacement-search" v-model="replacementSearch" :label="t('controlledReplacementSearch')" maxlength="200" /><UiButton variant="secondary" :disabled="loading" @click="findReplacements">{{ t('controlledFindReplacement') }}</UiButton><SelectInput id="controlled-replacement" v-model="actionForm.replaced_by_id" :label="t('controlledReplacement')" :options="replacementOptions" :error-key="fields.replaced_by_id?.[0]" required /><p class="text-sm text-slate-600">{{ t('controlledReplacementHelp') }}</p></template>
                </fieldset>
                <div class="flex gap-3"><UiButton type="submit" :loading="saving" :disabled="loading">{{ t('uiConfirm') }}</UiButton><UiButton variant="secondary" :disabled="saving" @click="action = ''">{{ t('uiCancel') }}</UiButton></div>
            </form>
            <DocumentStorage mode="physical" v-if="detail.storage_url" :key="detail.id" :endpoint="detail.storage_url" :scope="scope" :csrf-token="csrfToken" :revisions="detail.revisions" :fixed-revision="attachmentRevision?.id" @failure="fail" />
            <h3 class="font-semibold">{{ t('controlledHistory') }}</h3>
            <details v-for="revision in detail.revisions.toReversed()" :key="revision.id" :open="revision.id === detail.authoritative_revision_id || (!authoritative && revision.status === 'draft')" class="rounded-xl border border-slate-200 p-4">
                <summary class="cursor-pointer font-semibold">{{ revision.full_identifier || `${t('controlledRevision')} ${revision.identifier}` }} · {{ t(`controlledStatus-${revision.status}`) }} · {{ language === 'lo' ? revision.title_lo || revision.title_en : revision.title_en }}</summary>
                <dl class="mt-4 grid gap-4 md:grid-cols-2"><div v-for="field in detailFields" :key="field"><dt class="text-sm text-slate-600">{{ t(`controlled-${field}`) }}</dt><dd class="whitespace-pre-wrap wrap-anywhere">{{ field.endsWith('_date') ? formatDate(revision[field]) : revision[field] || '—' }}</dd></div>
                    <template v-if="revision.released_at"><div><dt class="text-sm text-slate-600">{{ t('controlledReleased') }}</dt><dd>{{ formatDateTime(revision.released_at) }} · {{ t('controlledActor') }} {{ revision.released_by }}</dd></div><div><dt class="text-sm text-slate-600">{{ t('controlledReason') }}</dt><dd class="whitespace-pre-wrap wrap-anywhere">{{ revision.release_reason }}</dd></div></template>
                </dl>
            </details>
            <section v-if="related.length" class="flex flex-col gap-2"><h3 class="font-semibold">{{ t('controlledRelationships') }}</h3><p v-for="item in related" :key="item.id">{{ t(`controlledRelation-${item.relationship}`) }}: <button class="font-semibold text-teal-800 hover:underline" :disabled="loading || saving" @click="show(item)">{{ item.document_code }}</button></p></section>
            <details class="rounded-xl border border-slate-200 p-4"><summary class="cursor-pointer font-semibold">{{ t('controlledAudit') }}</summary><ul class="mt-3 flex flex-col gap-3"><li v-for="audit in audits" :key="audit.id" class="text-sm"><p>{{ t(`controlledAudit-${audit.action}`) }} · {{ formatDateTime(audit.created_at) }} · {{ t('controlledActor') }} {{ audit.actor_id }}</p><p v-if="audit.reason" class="whitespace-pre-wrap wrap-anywhere text-slate-600">{{ audit.reason }}</p></li></ul></details>
        </section>
        <ToastNotifications :items="items" @dismiss="dismiss" />
    </section>
</template>
