<script setup>
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { formatDate, formatDateTime } from '../utilities/dates.js';
import StatusBadge from './ui/StatusBadge.vue';
import PdfPreviewDialog from './ui/PdfPreviewDialog.vue';
import UiButton from './ui/UiButton.vue';
import OutgoingDispatch from './OutgoingDispatch.vue';
import TextInput from './ui/TextInput.vue';
import TextareaInput from './ui/TextareaInput.vue';
import SelectInput from './ui/SelectInput.vue';
import DataTable from './ui/DataTable.vue';
import ConfirmDialog from './ui/ConfirmDialog.vue';
import ToastNotifications from './ui/ToastNotifications.vue';
import { useNotifications } from './ui/useNotifications.js';
import { useLocalization } from '../localization/index.js';
import { createJsonClient } from '../utilities/jsonClient.js';

const props = defineProps({ scope: { type: Object, required: true }, endpoint: { type: String, required: true }, csrfToken: { type: String, required: true }, incomingEndpoint: { type: String, default: '' } });
const emit = defineEmits(['unauthorized']);
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
const previewFilename = computed(() => previewPath.value === '/export' ? 'outgoing-correspondence.pdf' : 'outgoing-correspondence-' + detail.value?.id + '.pdf');
const listError = ref('');
const detail = ref(null);
const audits = ref([]);
const handling = ref(null);
const search = ref('');
const editing = ref(false);
const confirmation = ref(false);
const editorElement = ref(null);
const form = reactive({});
let controller;
let initialNavigation = true;
const textFields = ['signatory_name', 'signatory_title', 'document_date', 'subject'];
const recipientFields = ['organization', 'contact_name', 'email', 'phone', 'address'];
const recipientKinds = computed(() => ['primary', 'copy'].map(value => ({ value, label: t(`outgoingRecipient-${value}`) })));
function emptyRecipient(kind = 'primary') { return { kind, organization: '', contact_name: '', email: '', phone: '', address: '' }; }
const descriptionFields = ['description_en', 'description_lo'];
const canManage = computed(() => props.scope.permissions.includes('outgoing.drafts.manage'));
const canRegister = computed(() => props.scope.permissions.includes('outgoing.register') && props.scope.permissions.includes('numbering.issue'));
const label = item => item?.[language.value === 'lo' ? 'name_lo' : 'name_en'] ?? '';
const typeOptions = computed(() => [{ value: '', label: t('uiChoose') }, ...types.value.map(item => ({ value: item.id, label: `${label(item.category)} / ${label(item)}` }))]);
const classificationOptions = computed(() => ['normal', 'confidential', 'highly_confidential'].map(value => ({ value, label: t(`confidentiality-${value}`) })));
const priorityOptions = computed(() => ['normal', 'urgent'].map(value => ({ value, label: t(`outgoingPriority-${value}`) })));
const columns = computed(() => [{ key: 'subject', label: t('outgoing-subject') }, { key: 'document_date', label: t('outgoing-document_date'), type: 'date' }, { key: 'status_label', label: t('uiStatus') }, { key: 'actions', label: t('rbacActions') }]);
const displayRows = computed(() => rows.value.map(item => ({ ...item, internal_number: item.internal_number ?? '—', official_number: item.official_number ?? '—', status_label: t(`outgoingStatus-${item.operations?.status ?? item.status}`) })));
async function backToList() { editing.value = false; detail.value = null; error.value = ''; await nextTick(); document.getElementById('outgoing-list-title')?.focus(); }
function scopeData() { return { company_id: props.scope.company_id, site_id: props.scope.site_id, department_id: props.scope.department_id }; }
function url(path = '') { return `${props.endpoint}${path}?${new URLSearchParams(Object.entries({ ...scopeData(), page: page.value, search: path ? undefined : search.value }).filter(([, value]) => value != null))}`; }
function reportUrl(format, path = '/export') {
    const data = { ...scopeData(), language: language.value, ...(path === '/export' ? { search: search.value } : {}), format };
    return `${props.endpoint}${path}?${new URLSearchParams(Object.entries(data).filter(([, value]) => value != null))}`;
}
function preview(path = '/export') { previewPath.value = path; previewOpen.value = true; }
function fail(failure) {
    if (failure.name === 'AbortError') { return; }
    fields.value = failure.fields ?? {};
    error.value = Object.values(fields.value).flat().find(value => /^(outgoing|rbac|numbering)/.test(value)) ?? failure.message;
    if (!/^(outgoing|rbac|numbering)/.test(error.value)) { error.value = 'rbacRequestFailed'; }
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
async function reset() {
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
    try { const result = await request(url(`/${row.id}`), { signal }); if (!signal.aborted) { detail.value = result.data; audits.value = result.audits; handling.value = result; await nextTick(); document.getElementById('outgoing-detail-title')?.focus(); } }
    catch (failure) { if (!signal.aborted) { fail(failure); } }
    finally { if (!signal.aborted) { loading.value = false; } }
}
async function edit(create = false) {
    if (create) { detail.value = null; audits.value = []; handling.value = null; }
    const record = detail.value;
    for (const field of [...textFields, ...descriptionFields]) { form[field] = record?.[field] ?? ''; }
    Object.assign(form, { type_id: record?.type_id ?? '', priority: record?.priority ?? 'normal', classification: record?.classification ?? 'normal', recipients: record?.recipients?.map(item => Object.fromEntries(['kind', ...recipientFields].map(field => [field, item[field] ?? '']))) ?? [emptyRecipient()] });
    fields.value = {}; error.value = ''; editing.value = true;
    await nextTick(); editorElement.value?.querySelector('h2')?.focus();
}
async function save() {
    if (saving.value || !canManage.value) { return; }
    const signal = controller.signal;
    const body = { ...scopeData(), ...form };
    if (detail.value) { delete body.classification; }
    saving.value = true; error.value = ''; fields.value = {};
    try {
        const result = await request(`${props.endpoint}${detail.value ? `/${detail.value.id}` : ''}`, { method: detail.value ? 'PATCH' : 'POST', body, signal });
        if (!signal.aborted) { editing.value = false; await show(result.data); notify({ type: 'success', messageKey: 'outgoingSaved' }); await load(signal); }
    } catch (failure) { if (!signal.aborted) { fail(failure); await nextTick(); editorElement.value?.querySelector('[aria-invalid="true"]')?.focus(); } }
    finally { if (!signal.aborted) { saving.value = false; } }
}
async function register() {
    if (saving.value || !detail.value || !canRegister.value) { return; }
    const signal = controller.signal; const id = detail.value.id;
    saving.value = true; error.value = ''; fields.value = {};
    try {
        const result = await request(url(`/${id}/register`), { method: 'POST', body: scopeData(), signal });
        if (!signal.aborted) { confirmation.value = false; await show(result.data); notify({ type: 'success', messageKey: 'outgoingRegistered' }); await load(signal); }
    } catch (failure) { if (!signal.aborted) { confirmation.value = false; fail(failure); } }
    finally { if (!signal.aborted) { saving.value = false; } }
}
async function operationSaved(record) { await show(record); await load(); }
watch(() => props.scope.key, reset, { immediate: true });
onBeforeUnmount(() => controller?.abort());
</script>

<template>
    <section class="flex flex-col gap-5">
        <div v-if="!editing && !detail" class="flex flex-wrap items-center justify-between gap-3"><h2 id="outgoing-list-title" tabindex="-1" class="text-lg font-semibold">{{ t('outgoingTitle') }}</h2><UiButton v-if="canManage" :disabled="saving || loading" @click="edit(true)">{{ t('outgoingCreate') }}</UiButton></div>
        <UiButton v-if="editing || detail" variant="secondary" class="self-start" :disabled="saving" @click="backToList">{{ t('backToRegister') }} · {{ t('outgoingTitle') }}</UiButton>
        <p v-if="error" role="alert" class="rounded-xl bg-red-50 p-4 text-red-800">{{ t(error) }}</p>
        <form v-if="!editing && !detail" class="flex flex-wrap items-end gap-3" @submit.prevent="page = 1; load()"><TextInput id="outgoing-search" v-model="search" :label="t('outgoingSearch')" maxlength="200" class="grow" /><UiButton type="submit" variant="secondary" :disabled="loading">{{ t('incomingApplyFilters') }}</UiButton></form>
        <div v-if="!editing && !detail" class="flex flex-wrap gap-2"><a :href="reportUrl('xlsx')" class="inline-flex min-h-10 items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">{{ t('reportDownloadXlsx') }}</a><UiButton variant="secondary" :disabled="loading || saving" @click="preview()">{{ t('reportPreviewPdf') }}</UiButton></div>
        <DataTable v-if="!editing && !detail" v-model:page="page" :error="listError" :rows="displayRows" :columns="columns" :caption="t('outgoingTitle')" :loading="loading" server-side :total="total" :per-page="10" @change="load()">
            <template #cell-subject="{ row }"><button type="button" class="text-left font-semibold text-teal-800 hover:underline disabled:opacity-50" :disabled="saving || loading" @click="show(row)">{{ row.subject }}</button><span class="mt-1 block max-w-64 font-mono text-xs wrap-anywhere text-slate-500">{{ row.internal_number }}<span v-if="row.official_number !== '—'" class="block">{{ row.official_number }}</span></span></template>
            <template #cell-status_label="{ row }"><StatusBadge :status="row.operations?.status ?? row.status" :label="row.status_label" /></template>
            <template #cell-actions="{ row }"><UiButton variant="secondary" :disabled="saving || loading" @click="show(row)">{{ t('rbacDetails') }}</UiButton></template>
        </DataTable>
        <form id="outgoing-draft-form" v-if="editing" ref="editorElement" class="flex flex-col gap-5 rounded-2xl border border-slate-200 bg-white p-5" @submit.prevent="save">
            <h2 tabindex="-1" class="text-lg font-semibold">{{ t(detail ? 'outgoingEdit' : 'outgoingCreate') }}</h2>
            <p class="text-sm text-slate-600">{{ t('outgoingDraftRules') }}</p>
            <fieldset :disabled="saving" class="grid gap-4 md:grid-cols-2"><legend class="mb-3 font-semibold">{{ t('documentInformation') }}</legend>
                <SelectInput id="outgoing-type" v-model="form.type_id" :label="t('outgoingType')" :options="typeOptions" :error-key="fields.type_id?.[0]" required />
                <SelectInput v-if="!detail" id="outgoing-classification" v-model="form.classification" :label="t('outgoingClassification')" :options="classificationOptions" :helper="t(`confidentiality-${form.classification}-description`)" :error-key="fields.classification?.[0]" required />
                <SelectInput id="outgoing-priority" v-model="form.priority" :label="t('outgoingPriority')" :options="priorityOptions" :error-key="fields.priority?.[0]" required />
                <TextInput v-for="field in textFields.filter(field => !['sender_contact', 'sender_email', 'sender_phone', 'signatory_title'].includes(field))" :id="`outgoing-${field}`" :key="field" v-model="form[field]" :label="t(`outgoing-${field}`)" :class="field === 'subject' ? 'md:col-span-2' : ''" :type="field.endsWith('_date') ? 'date' : 'text'" :error-key="fields[field]?.[0]" :required="['signatory_name', 'subject', 'document_date'].includes(field)" :maxlength="field === 'subject' ? 500 : 255" />
            </fieldset>
            <details :open="['sender_contact', 'sender_email', 'sender_phone', 'signatory_title', ...descriptionFields].some(field => fields[field])" class="rounded-xl border border-slate-200 p-4"><summary class="cursor-pointer text-sm font-semibold">{{ t('additionalInformation') }}</summary><div class="mt-4 grid gap-4 md:grid-cols-2">
                <TextInput v-for="field in textFields.filter(field => ['sender_contact', 'sender_email', 'sender_phone', 'signatory_title'].includes(field))" :id="`outgoing-${field}`" :key="field" v-model="form[field]" :label="t(`outgoing-${field}`)" :type="field.endsWith('email') ? 'email' : 'text'" :error-key="fields[field]?.[0]" :maxlength="field.endsWith('phone') ? 64 : 255" />
                <TextareaInput v-for="field in descriptionFields" :id="`outgoing-${field}`" :key="field" v-model="form[field]" :label="t(`outgoing-${field}`)" :error-key="fields[field]?.[0]" maxlength="5000" />
            </div></details>
            <section class="flex flex-col gap-4" aria-labelledby="outgoing-recipients-title">
                <h3 id="outgoing-recipients-title" class="font-semibold">{{ t('outgoingRecipients') }}</h3>
                <p class="text-sm text-slate-600">{{ t('outgoingRecipientsHelp') }}</p>
                <fieldset v-for="(recipient, index) in form.recipients" :key="index" :disabled="saving" class="grid gap-4 rounded-xl border border-slate-200 p-4 md:grid-cols-2">
                    <legend class="px-2 font-medium">{{ t('outgoingRecipient') }} {{ index + 1 }}</legend>
                    <SelectInput :id="`outgoing-recipient-${index}-kind`" v-model="recipient.kind" :label="t('outgoingRecipientKind')" :options="recipientKinds" :error-key="fields[`recipients.${index}.kind`]?.[0]" required />
                    <TextInput v-for="field in recipientFields" :id="`outgoing-recipient-${index}-${field}`" :key="field" v-model="recipient[field]" :label="t(`outgoingRecipient-${field}`)" :type="field === 'email' ? 'email' : 'text'" :required="field === 'organization'" :maxlength="field === 'address' ? 500 : field === 'phone' ? 64 : 255" :error-key="fields[`recipients.${index}.${field}`]?.[0]" />
                    <UiButton variant="secondary" :disabled="saving" @click="form.recipients.splice(index, 1)">{{ t('outgoingRemoveRecipient') }}</UiButton>
                </fieldset>
                <div class="flex flex-wrap gap-2"><UiButton variant="secondary" :disabled="saving || form.recipients.length >= 50" @click="form.recipients.push(emptyRecipient())">{{ t('outgoingAddPrimary') }}</UiButton><UiButton variant="secondary" :disabled="saving || form.recipients.length >= 50" @click="form.recipients.push(emptyRecipient('copy'))">{{ t('outgoingAddCopy') }}</UiButton></div>
            </section>
            <div class="sticky bottom-0 flex flex-wrap gap-3 border-t border-slate-200 bg-white py-3"><UiButton type="submit" :loading="saving">{{ t('outgoingSave') }}</UiButton><UiButton variant="secondary" :disabled="saving" @click="editing = false">{{ t('uiCancel') }}</UiButton></div>
        </form>
        <section v-else-if="detail" class="flex flex-col gap-5 rounded-2xl border border-slate-200 bg-white p-5" aria-labelledby="outgoing-detail-title">
            <div class="flex flex-wrap items-center justify-between gap-3"><h2 id="outgoing-detail-title" tabindex="-1" class="text-lg font-semibold">{{ detail.subject }}</h2><div v-if="detail.status === 'draft'" class="flex gap-3"><UiButton v-if="canManage" variant="secondary" :disabled="saving || loading" @click="edit()">{{ t('outgoingEdit') }}</UiButton><UiButton v-if="canRegister" :disabled="saving || loading" @click="confirmation = true">{{ t('outgoingRegister') }}</UiButton></div></div>
            <div><UiButton variant="secondary" :disabled="loading || saving" @click="preview(`/${detail.id}/pdf`)">{{ t('reportPreviewDetailPdf') }}</UiButton></div>
            <dl class="grid gap-4 md:grid-cols-2">
                <div><dt class="text-sm text-slate-600">{{ t('outgoingNumber') }}</dt><dd class="font-mono">{{ detail.internal_number ?? '—' }}</dd></div>
                <div><dt class="text-sm text-slate-600">{{ t('outgoingOfficialNumber') }}</dt><dd class="font-mono">{{ detail.official_number ?? '—' }}</dd></div>
                <div><dt class="text-sm text-slate-600">{{ t('uiStatus') }}</dt><dd>{{ t(`outgoingStatus-${detail.operations?.status ?? detail.status}`) }}</dd></div>
                <div><dt class="text-sm text-slate-600">{{ t('outgoingType') }}</dt><dd>{{ label(detail.category) }} / {{ label(detail.type) }}</dd></div>
                <div><dt class="text-sm text-slate-600">{{ t('outgoingClassification') }}</dt><dd>{{ t(`confidentiality-${detail.classification}`) }}</dd></div>
                <div><dt class="text-sm text-slate-600">{{ t('outgoingPriority') }}</dt><dd>{{ t(`outgoingPriority-${detail.priority}`) }}</dd></div>
                <div v-for="field in ['signatory_name', 'document_date']" :key="field"><dt class="text-sm text-slate-600">{{ t(`outgoing-${field}`) }}</dt><dd class="whitespace-pre-wrap wrap-anywhere">{{ field.endsWith('_date') ? formatDate(detail[field]) : detail[field] || '—' }}</dd></div>
            </dl>
            <details class="rounded-xl border border-slate-200 p-4"><summary class="cursor-pointer text-sm font-semibold">{{ t('additionalInformation') }}</summary><dl class="mt-4 grid gap-4 md:grid-cols-2">
                <div v-for="field in [...textFields.filter(field => !['subject', 'signatory_name', 'document_date'].includes(field)), ...descriptionFields]" :key="field"><dt class="text-sm text-slate-600">{{ t(`outgoing-${field}`) }}</dt><dd class="whitespace-pre-wrap wrap-anywhere">{{ detail[field] || '—' }}</dd></div>
                <div v-if="detail.registered_at"><dt class="text-sm text-slate-600">{{ t('outgoingRegisteredAt') }}</dt><dd>{{ formatDateTime(detail.registered_at) }}</dd></div>
                <div v-if="detail.registered_by"><dt class="text-sm text-slate-600">{{ t('outgoingRegisteredBy') }}</dt><dd>{{ detail.registered_by }}</dd></div>
            </dl></details>
            <h3 class="font-semibold">{{ t('outgoingRecipients') }}</h3>
            <ul class="flex flex-col gap-3"><li v-for="(recipient, index) in detail.recipients" :key="index" class="rounded-xl border border-slate-200 p-3"><p class="font-medium">{{ t(`outgoingRecipient-${recipient.kind}`) }} · {{ recipient.organization }}</p><p v-for="field in recipientFields.filter(field => field !== 'organization' && recipient[field])" :key="field" class="whitespace-pre-wrap wrap-anywhere">{{ t(`outgoingRecipient-${field}`) }}: {{ recipient[field] }}</p></li></ul>
            <p v-if="detail.status === 'registered'" class="text-sm text-slate-600">{{ t('outgoingImmutable') }}</p>
            <OutgoingDispatch v-if="detail.status === 'registered'" :key="detail.id" :record="detail" :scope="scope" :endpoint="endpoint" :incoming-endpoint="incomingEndpoint" :csrf-token="csrfToken" :history="handling?.history ?? []" :related-incoming="handling?.related_incoming ?? []" @failure="fail" @saved="operationSaved" />
            <h3 class="font-semibold">{{ t('outgoingHistory') }}</h3>
            <ul class="flex flex-col gap-2"><li v-for="audit in audits" :key="audit.id">{{ t(`outgoingAudit-${audit.action}`) }} · {{ formatDateTime(audit.created_at) }} · {{ t('outgoingActor') }} {{ audit.actor_id }}</li></ul>
        </section>
        <PdfPreviewDialog v-if="previewOpen" v-model="previewOpen" :url="previewUrl" :filename="previewFilename" :title="previewPath === '/export' ? t('outgoingTitle') : detail?.subject || t('reportPreviewDetailPdf')" :csrf-token="csrfToken" @failure="fail" />
        <ConfirmDialog v-model="confirmation" :title="t('outgoingRegister')" :message="t('outgoingRegisterConfirm')" :loading="saving" @confirm="register" />
        <ToastNotifications :items="items" @dismiss="dismiss" />
    </section>
</template>
