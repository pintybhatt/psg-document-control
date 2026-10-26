<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { createJsonClient } from '../utilities/jsonClient.js';
import { formatDate, formatDateTime } from '../utilities/dates.js';
import { useLocalization } from '../localization/index.js';
import UiButton from './ui/UiButton.vue';
import TextInput from './ui/TextInput.vue';
import TextareaInput from './ui/TextareaInput.vue';
import SelectInput from './ui/SelectInput.vue';
import PdfPreviewDialog from './ui/PdfPreviewDialog.vue';

const props = defineProps({ endpoint: { type: String, required: true }, scope: { type: Object, required: true }, csrfToken: { type: String, required: true }, revisions: { type: Array, default: () => [] }, authoritativeRevision: { type: Number, default: null } });
const emit = defineEmits(['failure', 'changed']);
const { t } = useLocalization();
const request = createJsonClient(props.csrfToken);
const expanded = ref(false), busy = ref(false), loaded = ref(false), error = ref(''), success = ref('');
const attachments = ref([]), copies = ref([]), history = ref([]), locations = ref([]), people = ref([]);
const revisionId = ref(''), writable = ref(false), maxKb = ref(25600);
const fileInput = ref(null), uploadOpen = ref(false), replacing = ref(null), selectedFile = ref(null), uploadReason = ref('');
const copyOpen = ref(false), locationOpen = ref(false), selectedCopy = ref(null), action = ref('');
const copyForm = reactive({ identifier: '', kind: 'original', location_id: '', reason: '' });
const locationForm = reactive({ room: '', cabinet: '', shelf: '', box: '' });
const custodyForm = reactive({ custodian_id: '', location_id: '', checkout_date: '', expected_return_date: '', reason: '', status: 'stored', correction_event_id: '', observation: 'verified' });
const preview = ref(null), pdfOpen = ref(false), imageUrl = ref('');
let controller = new AbortController();
const capabilities = ref({ preview: false, download: false });
const can = permission => props.scope.permissions.includes(permission);
const choice = rows => [{ value: '', label: t('uiChoose') }, ...rows];
const revisionOptions = computed(() => props.revisions.map(row => ({ value: row.id, label: `${t('controlledRevision')} ${row.identifier} · ${t(`controlledStatus-${row.status}`)}` })));
const locationOptions = computed(() => choice(locations.value.map(row => ({ value: row.id, label: row.label }))));
const peopleOptions = computed(() => choice(people.value.map(row => ({ value: row.id, label: row.name }))));
const correctionOptions = computed(() => choice(history.value.filter(row => row.subject_id === selectedCopy.value?.id).map(row => ({ value: row.id, label: `${formatDateTime(row.created_at)} · ${t(`storageEvent-${row.action}`)}` }))));
const statusOptions = computed(() => ['stored', 'checked_out', 'missing'].map(value => ({ value, label: t(`storageStatus-${value}`) })));
const needsCustodian = computed(() => action.value === 'checkout' || (action.value === 'transfer' && selectedCopy.value?.status === 'checked_out') || (action.value === 'correct' && custodyForm.status === 'checked_out'));
const versions = attachment => attachment.versions.toReversed();
const initialRevision = () => props.authoritativeRevision ?? props.revisions.at(-1)?.id ?? '';
function url(path, extra = {}) {
    const data = { company_id: props.scope.company_id, site_id: props.scope.site_id, department_id: props.scope.department_id, ...(revisionId.value ? { revision_id: revisionId.value } : {}), ...extra };
    return `${props.endpoint.replace(/\/$/, '')}/${path}?${new URLSearchParams(Object.entries(data).filter(([, value]) => value != null && value !== ''))}`;
}
function clearPreview() { pdfOpen.value = false; preview.value = null; if (imageUrl.value) { URL.revokeObjectURL(imageUrl.value); imageUrl.value = ''; } }
function fail(failure) {
    if (failure.name === 'AbortError') { return; }
    error.value = Object.values(failure.fields ?? {}).flat().find(value => /^(storage|rbac)/.test(value)) ?? failure.message;
    if (!/^(storage|rbac)/.test(error.value)) { error.value = 'rbacRequestFailed'; }
    if ([401, 403, 404, 419].includes(failure.status)) {
        attachments.value = []; capabilities.value = { preview: false, download: false }; copies.value = []; history.value = []; people.value = []; locations.value = []; uploadOpen.value = false; action.value = ''; copyOpen.value = false; locationOpen.value = false; selectedFile.value = null; clearPreview();
        emit('failure', failure);
    }
}
async function load() {
    if (!expanded.value) { return; }
    const signal = controller.signal;
    busy.value = true; error.value = '';
    try {
        const [files, physical] = await Promise.all([request(url('attachments'), { signal }), request(url('copies'), { signal })]);
        if (signal.aborted) { return; }
        attachments.value = files.data; capabilities.value = files.capabilities; writable.value = files.writable; maxKb.value = files.max_upload_kb;
        copies.value = physical.data; history.value = physical.history; locations.value = physical.locations; people.value = physical.people; loaded.value = true;
    } catch (failure) { if (!signal.aborted) { fail(failure); } }
    finally { if (!signal.aborted) { busy.value = false; } }
}
async function toggle(event) { if (event.target !== event.currentTarget) { return; } expanded.value = event.target.open; if (expanded.value && !loaded.value) { await load(); } }
function beginUpload(attachment = null) { replacing.value = attachment; uploadOpen.value = true; uploadReason.value = ''; selectedFile.value = null; error.value = ''; success.value = ''; }
async function upload() {
    if (busy.value || !selectedFile.value) { return; }
    if (selectedFile.value.size > maxKb.value * 1024) { error.value = 'storageInvalidFile'; return; }
    const body = new FormData(); body.append('file', selectedFile.value); body.append('reason', uploadReason.value);
    if (replacing.value) { body.append('attachment_id', replacing.value.id); body.append('expected_version', replacing.value.versions.at(-1).version); }
    await mutate('attachments', body);
}
async function mutate(path, body) {
    if (busy.value) { return; }
    const signal = controller.signal;
    busy.value = true; error.value = ''; success.value = '';
    try {
        await request(url(path), { method: 'POST', body, signal });
        if (!signal.aborted) { uploadOpen.value = false; copyOpen.value = false; locationOpen.value = false; action.value = ''; selectedFile.value = null; success.value = 'storageSaved'; await load(); emit('changed'); }
    } catch (failure) { if (!signal.aborted) { fail(failure); } }
    finally { if (!signal.aborted) { busy.value = false; } }
}
async function download(version) {
    const signal = controller.signal; busy.value = true; error.value = '';
    try {
        const result = await request(url(`versions/${version.id}/download`), { signal, responseType: 'blob' });
        if (signal.aborted) { return; }
        const objectUrl = URL.createObjectURL(result.blob); const link = document.createElement('a'); link.href = objectUrl; link.download = version.original_name; document.body.append(link); link.click(); link.remove(); setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
    } catch (failure) { if (!signal.aborted) { fail(failure); } }
    finally { if (!signal.aborted) { busy.value = false; } }
}
async function openPreview(version) {
    clearPreview(); preview.value = version;
    if (version.mime_type === 'application/pdf') { pdfOpen.value = true; return; }
    const signal = controller.signal; busy.value = true;
    try { const result = await request(url(`versions/${version.id}/preview`), { signal, responseType: 'blob' }); if (!signal.aborted) { imageUrl.value = URL.createObjectURL(result.blob); } }
    catch (failure) { if (!signal.aborted) { fail(failure); } }
    finally { if (!signal.aborted) { busy.value = false; } }
}
function beginCopy() { Object.assign(copyForm, { identifier: '', kind: 'original', location_id: '', reason: '' }); copyOpen.value = true; }
function beginCustody(copy, value) {
    selectedCopy.value = copy; action.value = value; error.value = ''; success.value = '';
    Object.assign(custodyForm, { custodian_id: copy.custodian_id ?? '', location_id: copy.location_id, checkout_date: copy.checkout_date ?? '', expected_return_date: copy.expected_return_date ?? '', reason: '', status: copy.status, correction_event_id: '', observation: 'verified' });
}
async function changeCustody() {
    const body = { action: action.value, lock_version: selectedCopy.value.lock_version, reason: custodyForm.reason };
    if (needsCustodian.value) { Object.assign(body, { custodian_id: custodyForm.custodian_id, expected_return_date: custodyForm.expected_return_date }); if (action.value !== 'transfer') { body.checkout_date = custodyForm.checkout_date; } }
    if (action.value === 'correct') { Object.assign(body, { status: custodyForm.status, correction_event_id: custodyForm.correction_event_id }); }
    if (action.value === 'inventory') { body.observation = custodyForm.observation; } else { body.location_id = custodyForm.location_id; }
    await mutate(`copies/${selectedCopy.value.id}/events`, body);
}
watch(() => [props.endpoint, props.scope.key], () => { controller.abort(); controller = new AbortController(); clearPreview(); loaded.value = false; attachments.value = []; capabilities.value = { preview: false, download: false }; copies.value = []; history.value = []; locations.value = []; people.value = []; revisionId.value = initialRevision(); action.value = ''; uploadOpen.value = false; copyOpen.value = false; locationOpen.value = false; selectedFile.value = null; busy.value = false; error.value = ''; success.value = ''; if (expanded.value) { load(); } }, { immediate: true });
watch(revisionId, () => { controller.abort(); controller = new AbortController(); clearPreview(); loaded.value = false; attachments.value = []; capabilities.value = { preview: false, download: false }; copies.value = []; history.value = []; uploadOpen.value = false; action.value = ''; copyOpen.value = false; selectedFile.value = null; busy.value = false; if (expanded.value) { load(); } });
onBeforeUnmount(() => { controller.abort(); clearPreview(); });
</script>

<template>
    <details class="rounded-xl border border-slate-200 bg-slate-50 p-4" @toggle="toggle">
        <summary class="cursor-pointer font-semibold text-teal-900">{{ t('storageTitle') }}</summary>
        <div class="mt-4 flex flex-col gap-4" :aria-busy="busy">
            <SelectInput v-if="revisions.length" id="storage-revision" v-model="revisionId" :label="t('controlledRevision')" :options="revisionOptions" :disabled="busy" />
            <p v-if="error" role="alert" class="rounded-lg bg-red-50 p-3 text-sm text-red-800">{{ t(error) }}</p>
            <p v-if="success" role="status" class="text-sm text-teal-800">{{ t(success) }}</p>
            <p v-if="busy" role="status" class="text-sm">{{ t('uiLoading') }}</p>
            <div class="flex flex-wrap items-center justify-between gap-2"><h3 class="font-semibold">{{ t('storageAttachments') }}</h3><UiButton v-if="can('documents.attachments.manage') && writable" variant="secondary" :disabled="busy" @click="beginUpload()">{{ t('storageUpload') }}</UiButton></div>
            <p v-if="loaded && !writable" class="text-sm text-slate-600">{{ t('storageRevisionImmutable') }}</p>
            <p class="text-sm text-slate-600">{{ t('storageFormats', { size: Math.round(maxKb / 1024) }) }}</p>
            <p v-if="loaded && !attachments.length" class="text-sm text-slate-600">{{ t('storageNoAttachments') }}</p>
            <ul class="flex flex-col gap-3"><li v-for="attachment in attachments" :key="attachment.id" class="rounded-lg border border-slate-200 bg-white p-3">
                <p class="font-medium wrap-anywhere">{{ attachment.versions.at(-1).original_name }}</p>
                <div class="mt-2 flex flex-wrap gap-2"><UiButton v-if="capabilities.preview && attachment.versions.at(-1).previewable" variant="secondary" :disabled="busy" @click="openPreview(attachment.versions.at(-1))">{{ t('storagePreview') }}</UiButton><UiButton v-if="capabilities.download" variant="secondary" :disabled="busy" @click="download(attachment.versions.at(-1))">{{ t('storageDownload') }}</UiButton><UiButton v-if="writable && can('documents.attachments.manage')" variant="secondary" :disabled="busy" @click="beginUpload(attachment)">{{ t('storageReplace') }}</UiButton></div>
                <details class="mt-3"><summary class="cursor-pointer text-sm text-teal-800">{{ t('storageFileHistory') }}</summary><ul class="mt-2 flex flex-col gap-3"><li v-for="version in versions(attachment)" :key="version.id" class="text-sm wrap-anywhere"><p>{{ t('storageVersion') }} {{ version.version }} · {{ version.original_name }} · {{ Math.ceil(version.size / 1024) }} KB</p><p>{{ formatDateTime(version.uploaded_at) }} · {{ t('controlledActor') }} {{ version.uploaded_by }}</p><p class="whitespace-pre-wrap">{{ version.reason }}</p><div class="mt-1 flex flex-wrap gap-2"><UiButton v-if="capabilities.preview && version.previewable" variant="secondary" :disabled="busy" @click="openPreview(version)">{{ t('storagePreview') }}</UiButton><UiButton v-if="capabilities.download" variant="secondary" :disabled="busy" @click="download(version)">{{ t('storageDownload') }}</UiButton></div></li></ul></details>
            </li></ul>
            <form v-if="uploadOpen" id="storage-upload-form" class="grid gap-3 rounded-lg border border-teal-200 bg-white p-4" @submit.prevent="upload"><h4 class="font-semibold">{{ t(replacing ? 'storageReplace' : 'storageUpload') }}</h4><label class="flex flex-col gap-2 text-sm">{{ t('storageFile') }}<input ref="fileInput" type="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.webp,.tif,.tiff,.dwg,.dxf" required :disabled="busy" class="ui-control max-w-full" @change="selectedFile = $event.target.files[0] ?? null" /></label><TextareaInput id="storage-upload-reason" v-model="uploadReason" :label="t('controlledReason')" required maxlength="2000" :disabled="busy" /><div class="flex gap-2"><UiButton type="submit" :loading="busy">{{ t('storageUpload') }}</UiButton><UiButton variant="secondary" :disabled="busy" @click="uploadOpen = false">{{ t('uiCancel') }}</UiButton></div></form>
            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-200 pt-4"><h3 class="font-semibold">{{ t('storagePhysical') }}</h3><UiButton v-if="can('documents.custody.manage')" variant="secondary" :disabled="busy" @click="beginCopy">{{ t('storageAddCopy') }}</UiButton></div>
            <p v-if="loaded && !copies.length" class="text-sm text-slate-600">{{ t('storageNoCopies') }}</p>
            <ul class="flex flex-col gap-3"><li v-for="copy in copies" :key="copy.id" class="rounded-lg border border-slate-200 bg-white p-3"><p class="font-semibold wrap-anywhere">{{ copy.identifier }} · {{ t(`storageKind-${copy.kind}`) }}</p><p class="text-sm">{{ t('storageLocation') }}: {{ copy.location }}</p><p class="text-sm" :class="copy.overdue ? 'font-semibold text-red-800' : ''">{{ t(`storageStatus-${copy.status}`) }}{{ copy.overdue ? ` · ${t('storageOverdue')}` : '' }}</p><p v-if="copy.custodian" class="text-sm">{{ copy.custodian }} · {{ formatDate(copy.checkout_date) }} → {{ formatDate(copy.expected_return_date) }}</p><div v-if="can('documents.custody.manage')" class="mt-2 flex flex-wrap gap-2"><UiButton v-if="copy.status === 'stored'" variant="secondary" :disabled="busy" @click="beginCustody(copy, 'checkout')">{{ t('storageAction-checkout') }}</UiButton><UiButton v-if="copy.status === 'checked_out'" variant="secondary" :disabled="busy" @click="beginCustody(copy, 'return')">{{ t('storageAction-return') }}</UiButton><UiButton v-if="copy.status !== 'missing'" variant="secondary" :disabled="busy" @click="beginCustody(copy, 'transfer')">{{ t('storageAction-transfer') }}</UiButton><UiButton variant="secondary" :disabled="busy" @click="beginCustody(copy, 'inventory')">{{ t('storageAction-inventory') }}</UiButton><UiButton variant="secondary" :disabled="busy" @click="beginCustody(copy, 'correct')">{{ t('storageAction-correct') }}</UiButton></div></li></ul>
            <form v-if="copyOpen" id="storage-copy-form" class="grid gap-3 rounded-lg border border-teal-200 bg-white p-4 md:grid-cols-2" @submit.prevent="mutate('copies', copyForm)"><TextInput id="storage-copy-identifier" v-model="copyForm.identifier" :label="t('storageIdentifier')" required maxlength="100" :disabled="busy" /><SelectInput id="storage-copy-kind" v-model="copyForm.kind" :label="t('storageCopyKind')" :options="['original', 'copy'].map(value => ({ value, label: t(`storageKind-${value}`) }))" :disabled="busy" /><SelectInput id="storage-copy-location" v-model="copyForm.location_id" :label="t('storageLocation')" :options="locationOptions" required :disabled="busy" /><TextareaInput id="storage-copy-reason" v-model="copyForm.reason" :label="t('controlledReason')" required maxlength="2000" :disabled="busy" /><div class="flex gap-2"><UiButton type="submit" :loading="busy">{{ t('storageSave') }}</UiButton><UiButton variant="secondary" :disabled="busy" @click="copyOpen = false">{{ t('uiCancel') }}</UiButton></div></form>
            <form v-if="action" id="storage-custody-form" class="grid gap-3 rounded-lg border border-teal-200 bg-white p-4 md:grid-cols-2" @submit.prevent="changeCustody"><h4 class="font-semibold md:col-span-2">{{ t(`storageAction-${action}`) }} · {{ selectedCopy.identifier }}</h4><template v-if="action === 'correct'"><SelectInput id="storage-correction-event" v-model="custodyForm.correction_event_id" :label="t('storageCorrectionEvent')" :options="correctionOptions" required :disabled="busy" /><SelectInput id="storage-correction-status" v-model="custodyForm.status" :label="t('uiStatus')" :options="statusOptions" required :disabled="busy" /></template><template v-if="needsCustodian"><SelectInput id="storage-custodian" v-model="custodyForm.custodian_id" :label="t('storageCustodian')" :options="peopleOptions" required :disabled="busy" /><TextInput v-if="action !== 'transfer'" id="storage-checkout-date" v-model="custodyForm.checkout_date" type="date" :label="t('storageCheckoutDate')" required :disabled="busy" /><TextInput id="storage-return-date" v-model="custodyForm.expected_return_date" type="date" :label="t('storageExpectedReturn')" required :disabled="busy" /></template><SelectInput v-if="action !== 'inventory'" id="storage-custody-location" v-model="custodyForm.location_id" :label="t('storageLocation')" :options="locationOptions" required :disabled="busy" /><SelectInput v-if="action === 'inventory'" id="storage-observation" v-model="custodyForm.observation" :label="t('storageObservation')" :options="['verified', 'discrepancy'].map(value => ({ value, label: t(`storageObservation-${value}`) }))" required :disabled="busy" /><TextareaInput id="storage-custody-reason" v-model="custodyForm.reason" :label="t('controlledReason')" required maxlength="2000" :disabled="busy" /><div class="flex gap-2"><UiButton type="submit" :loading="busy">{{ t('uiConfirm') }}</UiButton><UiButton variant="secondary" :disabled="busy" @click="action = ''">{{ t('uiCancel') }}</UiButton></div></form>
            <details v-if="history.length"><summary class="cursor-pointer font-medium text-teal-800">{{ t('storageCustodyHistory') }}</summary><ul class="mt-3 flex flex-col gap-3"><li v-for="event in history.toReversed()" :key="event.id" class="text-sm"><p>{{ copies.find(copy => copy.id === event.subject_id)?.identifier }} · {{ t(`storageEvent-${event.action}`) }} · {{ formatDateTime(event.created_at) }} · {{ t('controlledActor') }} {{ event.actor_id }}</p><p v-if="event.after?.location_id" class="text-slate-600">{{ t('storageLocation') }}: {{ event.after.location_label || locations.find(location => location.id === event.after.location_id)?.label || '—' }}</p><p v-if="event.after?.custodian_name">{{ t('storageCustodian') }}: {{ event.after.custodian_name }} · {{ formatDate(event.after.checkout_date?.slice(0, 10)) }} → {{ formatDate(event.after.expected_return_date?.slice(0, 10)) }}</p><p v-if="event.after?.status">{{ t(`storageStatus-${event.after.status}`) }}</p><p v-if="event.after?.observation">{{ t(`storageObservation-${event.after.observation}`) }}</p><p class="whitespace-pre-wrap wrap-anywhere">{{ event.reason }}</p></li></ul></details>
            <details v-if="can('storage-locations.manage')" @toggle="locationOpen = $event.target.open"><summary class="cursor-pointer font-medium text-teal-800">{{ t('storageManageLocations') }}</summary><p class="mt-3 text-sm text-slate-600">{{ t('storageLocationHelp') }}</p><form v-if="locationOpen" id="storage-location-form" class="mt-3 grid gap-3 md:grid-cols-2" @submit.prevent="mutate('locations', locationForm)"><TextInput v-for="field in ['room', 'cabinet', 'shelf', 'box']" :id="`storage-${field}`" :key="field" v-model="locationForm[field]" :label="t(`storage-${field}`)" :required="field === 'room'" maxlength="100" :disabled="busy" /><UiButton type="submit" :loading="busy">{{ t('storageSave') }}</UiButton></form></details>
            <div v-if="imageUrl" role="region" :aria-label="t('storagePreview')" class="rounded-lg border bg-white p-3"><UiButton variant="secondary" @click="clearPreview">{{ t('reportClose') }}</UiButton><img :src="imageUrl" :alt="preview.original_name" class="mt-3 max-h-[70vh] max-w-full object-contain" /></div>
        </div>
        <PdfPreviewDialog v-if="pdfOpen && preview" v-model="pdfOpen" :url="url(`versions/${preview.id}/preview`)" :filename="preview.original_name" :title="preview.original_name" :csrf-token="csrfToken" :allow-download="false" @failure="fail" />
    </details>
</template>
