<script setup>
import DocumentApprovalPanel from './DocumentApprovalPanel.vue';
import CorrespondenceCorrection from './CorrespondenceCorrection.vue';
import DocumentStorage from './DocumentStorage.vue';
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { formatDate, formatDateTime } from '../utilities/dates.js';
import StatusBadge from './ui/StatusBadge.vue';
import PdfPreviewDialog from './ui/PdfPreviewDialog.vue';
import UiButton from './ui/UiButton.vue';
import PageBackButton from './ui/PageBackButton.vue';
import ExportActions from './ui/ExportActions.vue';
import IncomingOperations from './IncomingOperations.vue';
import TextInput from './ui/TextInput.vue';
import TextareaInput from './ui/TextareaInput.vue';
import SelectInput from './ui/SelectInput.vue';
import DataTable from './ui/DataTable.vue';
import ConfirmDialog from './ui/ConfirmDialog.vue';
import ToastNotifications from './ui/ToastNotifications.vue';
import { Icon } from '@iconify/vue';
import FormHeader from './ui/FormHeader.vue';
import FormSection from './ui/FormSection.vue';
import FormActions from './ui/FormActions.vue';
import fileImportIcon from '@iconify-icons/flowbite/file-import-outline';
import tagIcon from '@iconify-icons/flowbite/tag-outline';
import addressBookIcon from '@iconify-icons/flowbite/address-book-outline';
import annotationIcon from '@iconify-icons/flowbite/annotation-outline';
import infoIcon from '@iconify-icons/flowbite/info-circle-outline';
import { useNotifications } from './ui/useNotifications.js';
import { useLocalization } from '../localization/index.js';
import { createJsonClient } from '../utilities/jsonClient.js';

const props = defineProps({ scope: { type: Object, required: true }, endpoint: { type: String, required: true }, csrfToken: { type: String, required: true }, contextKey: { type: String, default: '' }, departmentOptions: { type: Array, default: () => [] } });
const emit = defineEmits(['unauthorized', 'update:contextKey']);
const { t, language } = useLocalization();
const request = createJsonClient(props.csrfToken);
const { items, notify, dismiss } = useNotifications();
const rows = ref([]);
const types = ref([]);
const total = ref(0);
const page = ref(1);
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const fields = ref({});
const previewOpen = ref(false);
const previewPath = ref('/export');
const previewUrl = computed(() => reportUrl('pdf', previewPath.value));
const previewFilename = computed(() => previewPath.value === '/export' ? 'incoming-correspondence.pdf' : 'incoming-correspondence-' + detail.value?.id + '.pdf');
const listError = ref('');
const detail = ref(null);
const audits = ref([]);
const handling = ref(null);
const filters = reactive({ search: '', filter_status: '', filter_channel: '', filter_due: '', filter_response: '' });
const editing = ref(false);
const confirmation = ref(false);
const editorElement = ref(null);
const form = reactive({});
let controller;
let initialNavigation = true;
let reopenCreateAfterScopeChange = false;
const contextLoading = ref(false);
const contextValid = ref(true);
const textFields = ['document_revision', 'sender_organization', 'sender_contact', 'sender_email', 'sender_phone', 'external_reference', 'correspondence_date', 'received_date', 'subject'];
const descriptionFields = ['description_en', 'description_lo'];
const additionalFields = ['document_revision', 'sender_contact', 'sender_email', 'sender_phone'];
const additionalOpen = computed(() => [...additionalFields, ...descriptionFields].some(field => fields.value[field]));
function fieldProps(field) {
    return {
        id: `incoming-${field}`,
        label: t(`incoming-${field}`),
        type: field.endsWith('_date') ? 'date' : field.endsWith('email') ? 'email' : 'text',
        helper: field === 'received_date' ? t('correspondenceReceivedDateHelp') : field.endsWith('_date') ? t('correspondenceDocumentDateHelp') : '',
        errorKey: fields.value[field]?.[0],
        required: ['sender_organization', 'subject', 'correspondence_date', 'received_date'].includes(field),
        maxlength: field === 'subject' ? 500 : field === 'document_revision' ? 30 : field.endsWith('phone') ? 64 : 255,
    };
}
const canManage = computed(() => props.scope.permissions.includes('incoming.drafts.manage'));
const canRegister = computed(() => props.scope.permissions.includes('incoming.register') && props.scope.permissions.includes('numbering.issue'));
const label = item => item?.[language.value === 'lo' ? 'name_lo' : 'name_en'] ?? '';
const typeOptions = computed(() => [{ value: '', label: t('uiChoose') }, ...types.value.map(item => ({ value: item.id, label: label(item) }))]);
const selectedType = computed(() => types.value.find(item => String(item.id) === String(form.type_id)));
const selectedCategoryLabel = computed(() => label(selectedType.value?.category));
const scopeDepartmentLabel = computed(() => label(props.scope.department) || t('siteWideDepartment'));
const authorizedDepartmentOptions = computed(() => props.departmentOptions.length ? props.departmentOptions : [{ value: props.scope.key, label: scopeDepartmentLabel.value }]);
const departmentContext = computed({
    get: () => props.contextKey || props.scope.key,
    set: value => {
        if (value === (props.contextKey || props.scope.key)) { return; }
        reopenCreateAfterScopeChange = editing.value && !detail.value;
        if (reopenCreateAfterScopeChange) { contextLoading.value = true; contextValid.value = false; }
        emit('update:contextKey', value);
    },
});
const channelOptions = computed(() => [{ value: '', label: t('uiChoose') }, ...['email', 'courier', 'hand_delivery', 'post', 'other'].map(value => ({ value, label: t(`incomingChannel-${value}`) }))]);
const filterStatuses = computed(() => [{ value: '', label: t('incomingAllStatuses') }, ...['draft', 'received', 'assigned', 'in_progress', 'responded', 'closed'].map(value => ({ value, label: t(`incomingStatus-${value}`) }))]);
const dueOptions = computed(() => [{ value: '', label: t('incomingAllDueDates') }, ...['overdue', 'today'].map(value => ({ value, label: t(`incomingDue-${value}`) }))]);
const responseOptions = computed(() => [{ value: '', label: t('incomingAllResponses') }, { value: 'pending', label: t('incomingResponsePendingLabel') }, { value: 'completed', label: t('incomingResponseCompleted') }]);
const classificationOptions = computed(() => ['normal', 'confidential', 'highly_confidential'].map(value => ({ value, label: t(`confidentiality-${value}`) })));
const priorityOptions = computed(() => ['normal', 'urgent'].map(value => ({ value, label: t(`incomingPriority-${value}`) })));
const columns = computed(() => [{ key: 'subject', label: t('incoming-subject') }, { key: 'sender_organization', label: t('incoming-sender_organization') }, { key: 'received_date', label: t('incoming-received_date'), type: 'date' }, { key: 'status_label', label: t('uiStatus') }, { key: 'due_label', label: t('incomingDueDate') }, { key: 'actions', label: t('rbacActions') }]);
const displayRows = computed(() => rows.value.map(item => ({ ...item, internal_number: item.internal_number ?? '—', status_label: t(`incomingStatus-${item.operations?.status ?? (item.status === 'registered' ? 'received' : item.status)}`), due_label: [formatDate(item.operations?.due_date, ''), item.due_indicator ? t(`incomingDue-${item.due_indicator}`) : ''].filter(Boolean).join(' · ') })));
async function backToList() { editing.value = false; detail.value = null; error.value = ''; await nextTick(); document.getElementById('incoming-list-title')?.focus(); }
function scopeData() { return { company_id: props.scope.company_id, site_id: props.scope.site_id, department_id: props.scope.department_id }; }
function url(path = '') { return `${props.endpoint}${path}?${new URLSearchParams(Object.entries({ ...scopeData(), page: page.value, ...(path === '' ? filters : {}) }).filter(([, value]) => value != null))}`; }
function reportUrl(format, path = '/export') {
    const data = { ...scopeData(), language: language.value, ...(path === '/export' ? filters : {}), format };
    return `${props.endpoint}${path}?${new URLSearchParams(Object.entries(data).filter(([, value]) => value != null))}`;
}
function preview(path = '/export') { previewPath.value = path; previewOpen.value = true; }
function fail(failure) {
    if (failure.name === 'AbortError') { return; }
    fields.value = failure.fields ?? {};
    error.value = Object.values(fields.value).flat().find(value => /^(incoming|rbac|numbering|approval)/.test(value)) ?? failure.message;
    if (!/^(incoming|rbac|numbering|approval)/.test(error.value)) { error.value = 'rbacRequestFailed'; }
    if ([401, 403, 404, 419].includes(failure.status)) {
        rows.value = []; detail.value = null; audits.value = []; handling.value = null; editing.value = false; confirmation.value = false; types.value = []; total.value = 0;
        if (failure.status !== 404) { controller?.abort(); emit('unauthorized', failure); }
    }
}
async function load(signal = controller.signal) {
    loading.value = true; if (error.value === listError.value) { error.value = ''; } listError.value = '';
    try { const result = await request(url(), { signal }); if (!signal.aborted) { rows.value = result.data; total.value = result.meta.total; } }
    catch (failure) { if (!signal.aborted) { fail(failure); listError.value = error.value; rows.value = []; total.value = 0; } }
    finally { if (!signal.aborted) { loading.value = false; } }
}
async function refreshCreateContext() {
    controller?.abort(); controller = new AbortController(); const signal = controller.signal;
    contextLoading.value = true; contextValid.value = false; saving.value = false; error.value = ''; fields.value = {};
    rows.value = []; total.value = 0; types.value = [];
    form.type_id = ''; form.classification = 'normal';

    try {
        const result = await request(url('/lookups'), { signal });
        if (signal.aborted) { return; }
        types.value = result.data;
        contextValid.value = canManage.value;

    } catch (failure) { if (!signal.aborted) { fail(failure); } }
    finally { if (!signal.aborted) { contextLoading.value = false; } }
}
async function reset() {
    const reopenCreate = reopenCreateAfterScopeChange;
    reopenCreateAfterScopeChange = false;
    if (reopenCreate) { await refreshCreateContext(); return; }
    contextLoading.value = false;
    contextValid.value = true;
    controller?.abort(); controller = new AbortController(); const signal = controller.signal;
    previewOpen.value = false; rows.value = []; types.value = []; detail.value = null; audits.value = []; handling.value = null; editing.value = false; confirmation.value = false; error.value = ''; listError.value = ''; fields.value = {}; page.value = 1; total.value = 0; saving.value = false;
    await load(signal);
    if (signal.aborted) { return; }
    try { const result = await request(url('/lookups'), { signal }); if (!signal.aborted) { types.value = result.data; } }
    catch (failure) { if (!signal.aborted) { fail(failure); } }
    if (initialNavigation && !signal.aborted && !error.value) {
        initialNavigation = false;
        const query = new URLSearchParams(window.location.search);
        if (/^[1-9][0-9]*$/.test(query.get('record') ?? '')) { await show({ id: query.get('record') }); }
        else if (query.get('create') === '1' && canManage.value) { await edit(true); }
    }
}
async function show(row) {
    const signal = controller.signal;
    detail.value = null; audits.value = []; handling.value = null; editing.value = false; error.value = ''; fields.value = {};
    loading.value = true;
    try { const result = await request(url(`/${row.id}`), { signal }); if (!signal.aborted) { detail.value = result.data; audits.value = result.audits; handling.value = result; await nextTick(); document.getElementById('incoming-detail-title')?.focus(); } }
    catch (failure) { if (!signal.aborted) { fail(failure); } }
    finally { if (!signal.aborted) { loading.value = false; } }
}
async function edit(create = false) {
    if (create) { detail.value = null; audits.value = []; handling.value = null; }
    const record = detail.value;
    for (const field of [...textFields, ...descriptionFields]) { form[field] = record?.[field] ?? ''; }
    Object.assign(form, { type_id: record?.type_id ?? '', priority: record?.priority ?? 'normal', channel: record?.channel ?? '', classification: record?.classification ?? 'normal' });
    fields.value = {}; error.value = ''; editing.value = true;
    await nextTick(); editorElement.value?.querySelector('h2')?.focus();
}
async function save() {
    if (contextLoading.value || !contextValid.value) { return; }
    if (saving.value || !canManage.value) { return; }
    const signal = controller.signal;
    const body = { ...scopeData(), ...form };
    if (detail.value) { delete body.classification; }
    saving.value = true; error.value = ''; fields.value = {};
    try {
        const result = await request(`${props.endpoint}${detail.value ? `/${detail.value.id}` : ''}`, { method: detail.value ? 'PATCH' : 'POST', body, signal });
        if (!signal.aborted) { editing.value = false; await show(result.data); notify({ type: 'success', messageKey: 'incomingSaved' }); await load(signal); }
    } catch (failure) { if (!signal.aborted) { fail(failure); await nextTick(); editorElement.value?.querySelector('[aria-invalid="true"]')?.focus(); } }
    finally { if (!signal.aborted) { saving.value = false; } }
}
async function register() {
    if (saving.value || !detail.value || !canRegister.value) { return; }
    const signal = controller.signal; const id = detail.value.id;
    saving.value = true; error.value = ''; fields.value = {};
    try {
        const result = await request(url(`/${id}/register`), { method: 'POST', body: scopeData(), signal });
        if (!signal.aborted) { confirmation.value = false; await show(result.data); notify({ type: 'success', messageKey: 'incomingRegistered' }); await load(signal); }
    } catch (failure) { if (!signal.aborted) { confirmation.value = false; fail(failure); } }
    finally { if (!signal.aborted) { saving.value = false; } }
}
async function operationSaved(record) { await show(record); await load(); }
watch(() => props.scope.key, reset, { immediate: true });
onBeforeUnmount(() => controller?.abort());
</script>

<template>
    <section class="incoming-register flex flex-col gap-5">
        <SelectInput v-if="!editing && !detail && authorizedDepartmentOptions.length > 1" id="incoming-context" v-model="departmentContext" :label="t('incomingReceivingDepartment')" :options="authorizedDepartmentOptions" :disabled="loading || saving" class="max-w-sm" />
        <div v-if="!editing && !detail" class="flex flex-wrap items-center justify-between gap-3"><h2 id="incoming-list-title" tabindex="-1" class="text-lg font-semibold">{{ t('incomingTitle') }}</h2><UiButton v-if="canManage" :disabled="saving || loading" @click="edit(true)">{{ t('incomingCreate') }}</UiButton></div>
        <PageBackButton v-if="editing || detail" :title="`${t('backToRegister')} · ${t('incomingTitle')}`" :disabled="saving" @click="backToList" />
        <p v-if="error" role="alert" class="rounded-xl bg-red-50 p-4 text-red-800">{{ t(error) }}</p>
        <form v-if="!editing && !detail" class="grid items-end gap-3 md:grid-cols-3 print:hidden" @submit.prevent="page = 1; load()">
            <TextInput id="incoming-filter-search" v-model="filters.search" :label="t('incomingSearch')" maxlength="200" />
            <SelectInput id="incoming-filter-status" v-model="filters.filter_status" :label="t('uiStatus')" :options="filterStatuses" />
            <UiButton type="submit" :disabled="loading || saving">{{ t('incomingApplyFilters') }}</UiButton>
            <details class="md:col-span-3"><summary class="cursor-pointer text-sm font-medium text-teal-800">{{ t('moreFilters') }}</summary><div class="mt-3 grid gap-3 md:grid-cols-3">
            <SelectInput id="incoming-filter-channel" v-model="filters.filter_channel" :label="t('incomingChannel')" :options="[{ value: '', label: t('incomingAllChannels') }, ...channelOptions.slice(1)]" />
            <SelectInput id="incoming-filter-due" v-model="filters.filter_due" :label="t('incomingDueDate')" :options="dueOptions" />
            <SelectInput id="incoming-filter-response" v-model="filters.filter_response" :label="t('incomingResponse')" :options="responseOptions" />
            </div></details>
        </form>

        <ExportActions v-if="!editing && !detail" :excel-href="reportUrl('xlsx')" :disabled="loading || saving" @preview="preview()" />
        <DataTable v-if="!editing && !detail" v-model:page="page" :error="listError" :rows="displayRows" :columns="columns" :caption="t('incomingTitle')" :loading="loading" server-side :total="total" :per-page="10" @change="load()">
            <template #cell-subject="{ row }"><button type="button" :title="row.subject" class="text-left font-semibold text-teal-800 hover:underline disabled:opacity-50" :disabled="saving || loading" @click="show(row)">{{ row.subject }}</button><span class="mt-1 block max-w-64 font-mono text-xs wrap-anywhere text-slate-500">{{ row.internal_number }}</span></template>
            <template #cell-status_label="{ row }"><StatusBadge :status="row.operations?.status ?? row.status" :label="row.status_label" /></template>
            <template #cell-actions="{ row }"><UiButton variant="secondary" :disabled="saving || loading" @click="show(row)">{{ t('rbacDetails') }}</UiButton></template>
        </DataTable>
        <form id="incoming-draft-form" v-if="editing" ref="editorElement" class="flex flex-col gap-5" @submit.prevent="save">
            <FormHeader :icon="fileImportIcon" :eyebrow="t('overview-incoming')" :title="t(detail ? 'incomingEdit' : 'incomingCreate')" :description="t('incomingDraftRules')">
                <p v-if="scope.permissions.includes('documents.attachments.manage')" class="flex items-start gap-2 text-sm text-slate-600"><Icon :icon="infoIcon" aria-hidden="true" class="mt-0.5 size-4 shrink-0 text-teal-700" />{{ t('storageDraftHelp') }}</p>
            </FormHeader>
            <FormSection :icon="tagIcon" :title="t('formSectionClassification')" :description="t('formSectionClassificationHelp')">
                <SelectInput id="incoming-department" v-model="departmentContext" :label="t('incomingReceivingDepartment')" :options="authorizedDepartmentOptions" :helper="t('documentDepartmentHelp')" :disabled="!!detail || saving" required />
                <SelectInput id="incoming-type" v-model="form.type_id" :label="t('documentType')" :options="typeOptions" :error-key="fields.type_id?.[0]" required />
                <TextInput id="incoming-category" :model-value="selectedCategoryLabel" :label="t('documentCategory')" :helper="t('documentCategoryHelp')" readonly />
                <SelectInput v-if="!detail" id="incoming-classification" v-model="form.classification" :label="t('incomingClassification')" :options="classificationOptions" :helper="t(`confidentiality-${form.classification}-description`)" :error-key="fields.classification?.[0]" required />
                <SelectInput id="incoming-channel" v-model="form.channel" :label="t('incomingChannel')" :options="channelOptions" :error-key="fields.channel?.[0]" required />
                <SelectInput id="incoming-priority" v-model="form.priority" :label="t('incomingPriority')" :options="priorityOptions" :error-key="fields.priority?.[0]" required />
            </FormSection>
            <FormSection :icon="addressBookIcon" :title="t('formSectionSender')" :description="t('formSectionSenderHelp')">
                <TextInput v-for="field in ['sender_organization', 'external_reference']" :key="field" v-model="form[field]" v-bind="fieldProps(field)" />
            </FormSection>
            <FormSection :icon="annotationIcon" :title="t('formSectionContent')" :description="t('formSectionContentHelp')">
                <div class="md:col-span-2"><TextInput v-model="form.subject" v-bind="fieldProps('subject')" /></div>
                <TextInput v-for="field in ['correspondence_date', 'received_date']" :key="field" v-model="form[field]" v-bind="fieldProps(field)" />
            </FormSection>
            <FormSection collapsible :open="additionalOpen" :icon="infoIcon" :title="t('additionalInformation')" :description="t('formSectionAdditionalHelp')">
                <TextInput v-for="field in additionalFields" :key="field" v-model="form[field]" v-bind="fieldProps(field)" />
                <TextareaInput v-for="field in descriptionFields" :id="`incoming-${field}`" :key="field" v-model="form[field]" :label="t(`incoming-${field}`)" :error-key="fields[field]?.[0]" maxlength="5000" />
            </FormSection>
            <p v-if="contextLoading" role="status" class="text-sm text-slate-600">{{ t('documentDepartmentLoading') }}</p>
            <FormActions><UiButton variant="secondary" :disabled="saving" @click="editing = false">{{ t('uiCancel') }}</UiButton><UiButton type="submit" :loading="saving" :disabled="contextLoading || !contextValid">{{ t('incomingSave') }}</UiButton></FormActions>
        </form>
        <section v-else-if="detail" class="flex flex-col gap-5 rounded-2xl border border-slate-200 bg-white p-5" aria-labelledby="incoming-detail-title">
            <div class="flex flex-wrap items-center justify-between gap-3"><h2 id="incoming-detail-title" tabindex="-1" class="text-lg font-semibold">{{ detail.subject }}</h2><div v-if="detail.status === 'draft'" class="flex gap-3"><UiButton v-if="canManage" variant="secondary" :disabled="saving || loading" @click="edit()">{{ t('incomingEdit') }}</UiButton><UiButton v-if="canRegister" :disabled="saving || loading" @click="confirmation = true">{{ t('incomingRegister') }}</UiButton></div></div>
            <DocumentApprovalPanel v-if="handling?.approval_execution" :execution="handling.approval_execution" :scope="scope" :csrf-token="csrfToken" kind="incoming" @failure="fail" @saved="operationSaved(detail)" />
            <CorrespondenceCorrection :key="detail.id" kind="incoming" :record="detail" :scope="scope" :endpoint="endpoint" :csrf-token="csrfToken" :history="handling?.history ?? []" @failure="fail" @saved="operationSaved" />
            <div><UiButton variant="secondary" :disabled="loading || saving" @click="preview(`/${detail.id}/pdf`)">{{ t('reportPreviewDetailPdf') }}</UiButton></div>
            <dl class="grid gap-4 md:grid-cols-2">
                <div><dt class="text-sm text-slate-600">{{ t('incomingNumber') }}</dt><dd class="font-mono">{{ detail.internal_number ?? '—' }}</dd></div>
                <div><dt class="text-sm text-slate-600">{{ t('uiStatus') }}</dt><dd>{{ t(`incomingStatus-${detail.operations?.status ?? (detail.status === 'registered' ? 'received' : detail.status)}`) }}</dd></div>
                <div><dt class="text-sm text-slate-600">{{ t('incomingReceivingDepartment') }}</dt><dd>{{ scopeDepartmentLabel }}</dd></div>
                <div><dt class="text-sm text-slate-600">{{ t('documentType') }}</dt><dd>{{ label(detail.type) }}</dd></div>
                <div><dt class="text-sm text-slate-600">{{ t('documentCategory') }}</dt><dd>{{ label(detail.category) }}</dd></div>
                <div><dt class="text-sm text-slate-600">{{ t('incomingClassification') }}</dt><dd>{{ t(`confidentiality-${detail.classification}`) }}</dd></div>
                <div><dt class="text-sm text-slate-600">{{ t('incomingChannel') }}</dt><dd>{{ detail.channel ? t(`incomingChannel-${detail.channel}`) : t('incomingUnspecified') }}</dd></div>
                <div><dt class="text-sm text-slate-600">{{ t('incomingPriority') }}</dt><dd>{{ t(`incomingPriority-${detail.priority}`) }}</dd></div>
                <div v-for="field in ['sender_organization', 'correspondence_date', 'received_date']" :key="field"><dt class="text-sm text-slate-600">{{ t(`incoming-${field}`) }}</dt><dd class="whitespace-pre-wrap wrap-anywhere">{{ field.endsWith('_date') ? formatDate(detail[field]) : detail[field] || '—' }}</dd></div>
            </dl>
            <details class="rounded-xl border border-slate-200 p-4"><summary class="cursor-pointer text-sm font-semibold">{{ t('additionalInformation') }}</summary><dl class="mt-4 grid gap-4 md:grid-cols-2">
                <div v-for="field in [...textFields.filter(field => !['subject', 'sender_organization', 'correspondence_date', 'received_date'].includes(field)), ...descriptionFields]" :key="field"><dt class="text-sm text-slate-600">{{ t(`incoming-${field}`) }}</dt><dd class="whitespace-pre-wrap wrap-anywhere">{{ detail[field] || '—' }}</dd></div>
                <div v-if="detail.registered_at"><dt class="text-sm text-slate-600">{{ t('incomingRegisteredAt') }}</dt><dd>{{ formatDateTime(detail.registered_at) }}<p class="text-sm text-slate-600">{{ t('correspondenceRegistrationDateHelp') }}</p></dd></div>
                <div v-if="detail.registered_by"><dt class="text-sm text-slate-600">{{ t('incomingRegisteredBy') }}</dt><dd>{{ detail.registered_by }}</dd></div>
            </dl></details>
            <p v-if="detail.status === 'registered'" class="text-sm text-slate-600">{{ t('correspondenceProtectedIdentity') }}</p>
            <IncomingOperations v-if="detail.status === 'registered'" :key="detail.id" :record="detail" :scope="scope" :endpoint="endpoint" :csrf-token="csrfToken" :history="handling?.history ?? []" :related="handling?.related ?? []" :responsible-user="handling?.responsible_user" :responsible-department="handling?.responsible_department" @failure="fail" @saved="operationSaved" @open-related="show" />
            <section v-if="handling?.related_outgoing?.length" class="flex flex-col gap-2 border-t border-slate-200 pt-4">
                <h3 class="font-semibold">{{ t('incomingRelatedOutgoing') }}</h3>
                <ul class="flex flex-col gap-2"><li v-for="item in handling.related_outgoing" :key="item.id" class="wrap-anywhere">{{ item.official_number || item.internal_number }} · {{ item.subject }}</li></ul>
            </section>
            <DocumentStorage v-if="detail.storage_url" :key="detail.id" :endpoint="detail.storage_url" :scope="scope" :csrf-token="csrfToken" @failure="fail" @changed="handling?.approval_execution && operationSaved(detail)" />
            <h3 class="font-semibold">{{ t('incomingHistory') }}</h3>
            <ul class="flex flex-col gap-2"><li v-for="audit in audits" :key="audit.id">{{ t(`incomingAudit-${audit.action}`) }} · {{ formatDateTime(audit.created_at) }} · {{ t('incomingActor') }} {{ audit.actor_id }}</li></ul>
        </section>
        <PdfPreviewDialog v-if="previewOpen" v-model="previewOpen" :url="previewUrl" :filename="previewFilename" :title="previewPath === '/export' ? t('incomingTitle') : detail?.subject || t('reportPreviewDetailPdf')" :csrf-token="csrfToken" @failure="fail" />
        <ConfirmDialog v-model="confirmation" :title="t('incomingRegister')" :message="t('incomingRegisterConfirm')" :loading="saving" @confirm="register" />
        <ToastNotifications :items="items" @dismiss="dismiss" />
    </section>
</template>
