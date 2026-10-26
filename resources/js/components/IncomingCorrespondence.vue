<script setup>
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue';
import UiButton from './ui/UiButton.vue';
import TextInput from './ui/TextInput.vue';
import TextareaInput from './ui/TextareaInput.vue';
import SelectInput from './ui/SelectInput.vue';
import DataTable from './ui/DataTable.vue';
import ConfirmDialog from './ui/ConfirmDialog.vue';
import ToastNotifications from './ui/ToastNotifications.vue';
import { useNotifications } from './ui/useNotifications.js';
import { useLocalization } from '../localization/index.js';
import { createJsonClient } from '../utilities/jsonClient.js';

const props = defineProps({ scope: { type: Object, required: true }, endpoint: { type: String, required: true }, csrfToken: { type: String, required: true } });
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
const detail = ref(null);
const audits = ref([]);
const editing = ref(false);
const confirmation = ref(false);
const editorElement = ref(null);
const form = reactive({});
let controller;
const textFields = ['sender_organization', 'sender_contact', 'sender_email', 'sender_phone', 'external_reference', 'correspondence_date', 'received_date', 'subject'];
const descriptionFields = ['description_en', 'description_lo'];
const canManage = computed(() => props.scope.permissions.includes('incoming.drafts.manage'));
const canRegister = computed(() => props.scope.permissions.includes('incoming.register') && props.scope.permissions.includes('numbering.issue'));
const label = item => item?.[language.value === 'lo' ? 'name_lo' : 'name_en'] ?? '';
const typeOptions = computed(() => [{ value: '', label: t('uiChoose') }, ...types.value.map(item => ({ value: item.id, label: `${label(item.category)} / ${label(item)}` }))]);
const classificationOptions = computed(() => ['normal', 'confidential', 'highly_confidential'].map(value => ({ value, label: t(`confidentiality-${value}`) })));
const priorityOptions = computed(() => ['normal', 'urgent'].map(value => ({ value, label: t(`incomingPriority-${value}`) })));
const columns = computed(() => [{ key: 'internal_number', label: t('incomingNumber'), type: 'reference' }, { key: 'subject', label: t('incoming-subject') }, { key: 'sender_organization', label: t('incoming-sender_organization') }, { key: 'received_date', label: t('incoming-received_date') }, { key: 'status_label', label: t('uiStatus') }, { key: 'actions', label: t('rbacActions') }]);
const displayRows = computed(() => rows.value.map(item => ({ ...item, internal_number: item.internal_number ?? '—', status_label: t(`incomingStatus-${item.status}`) })));
function scopeData() { return { company_id: props.scope.company_id, site_id: props.scope.site_id, department_id: props.scope.department_id }; }
function url(path = '') { return `${props.endpoint}${path}?${new URLSearchParams(Object.entries({ ...scopeData(), page: page.value }).filter(([, value]) => value != null))}`; }
function fail(failure) {
    if (failure.name === 'AbortError') { return; }
    fields.value = failure.fields ?? {};
    error.value = Object.values(fields.value).flat().find(value => /^(incoming|rbac|numbering)/.test(value)) ?? failure.message;
    if (!/^(incoming|rbac|numbering)/.test(error.value)) { error.value = 'rbacRequestFailed'; }
    if ([401, 403, 404, 419].includes(failure.status)) {
        rows.value = []; detail.value = null; audits.value = []; editing.value = false; confirmation.value = false; types.value = []; total.value = 0;
        if (failure.status !== 404) { controller?.abort(); emit('unauthorized', failure); }
    }
}
async function load(signal = controller.signal) {
    loading.value = true;
    try { const result = await request(url(), { signal }); if (!signal.aborted) { rows.value = result.data; total.value = result.meta.total; } }
    catch (failure) { if (!signal.aborted) { fail(failure); } }
    finally { if (!signal.aborted) { loading.value = false; } }
}
async function reset() {
    controller?.abort(); controller = new AbortController(); const signal = controller.signal;
    rows.value = []; types.value = []; detail.value = null; audits.value = []; editing.value = false; confirmation.value = false; error.value = ''; fields.value = {}; page.value = 1; total.value = 0; saving.value = false;
    await load(signal);
    if (signal.aborted) { return; }
    try { const result = await request(url('/lookups'), { signal }); if (!signal.aborted) { types.value = result.data; } }
    catch (failure) { if (!signal.aborted) { fail(failure); } }
}
async function show(row) {
    const signal = controller.signal;
    detail.value = null; audits.value = []; editing.value = false; error.value = ''; fields.value = {};
    loading.value = true;
    try { const result = await request(url(`/${row.id}`), { signal }); if (!signal.aborted) { detail.value = result.data; audits.value = result.audits; } }
    catch (failure) { if (!signal.aborted) { fail(failure); } }
    finally { if (!signal.aborted) { loading.value = false; } }
}
async function edit(create = false) {
    if (create) { detail.value = null; audits.value = []; }
    const record = detail.value;
    for (const field of [...textFields, ...descriptionFields]) { form[field] = record?.[field] ?? ''; }
    Object.assign(form, { type_id: record?.type_id ?? '', priority: record?.priority ?? 'normal', classification: record?.classification ?? 'normal' });
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
watch(() => props.scope.key, reset, { immediate: true });
onBeforeUnmount(() => controller?.abort());
</script>

<template>
    <section class="flex flex-col gap-5">
        <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-lg font-semibold">{{ t('incomingTitle') }}</h2><UiButton v-if="canManage" :disabled="saving || loading" @click="edit(true)">{{ t('incomingCreate') }}</UiButton></div>
        <p v-if="error" role="alert" class="rounded-xl bg-red-50 p-4 text-red-800">{{ t(error) }}</p>
        <DataTable v-model:page="page" :rows="displayRows" :columns="columns" :caption="t('incomingTitle')" :loading="loading" server-side :total="total" :per-page="10" @change="load()">
            <template #cell-actions="{ row }"><UiButton variant="secondary" :disabled="saving || loading" @click="show(row)">{{ t('rbacDetails') }}</UiButton></template>
        </DataTable>
        <form v-if="editing" ref="editorElement" class="flex flex-col gap-5 rounded-2xl border border-slate-200 bg-white p-5" @submit.prevent="save">
            <h2 tabindex="-1" class="text-lg font-semibold">{{ t(detail ? 'incomingEdit' : 'incomingCreate') }}</h2>
            <p class="text-sm text-slate-600">{{ t('incomingDraftRules') }}</p>
            <div class="grid gap-4 md:grid-cols-2">
                <SelectInput id="incoming-type" v-model="form.type_id" :label="t('incomingType')" :options="typeOptions" :error-key="fields.type_id?.[0]" required />
                <SelectInput v-if="!detail" id="incoming-classification" v-model="form.classification" :label="t('incomingClassification')" :options="classificationOptions" :helper="t(`confidentiality-${form.classification}-description`)" :error-key="fields.classification?.[0]" required />
                <SelectInput id="incoming-priority" v-model="form.priority" :label="t('incomingPriority')" :options="priorityOptions" :error-key="fields.priority?.[0]" required />
                <TextInput v-for="field in textFields" :id="`incoming-${field}`" :key="field" v-model="form[field]" :label="t(`incoming-${field}`)" :type="field.endsWith('_date') ? 'date' : field === 'sender_email' ? 'email' : 'text'" :error-key="fields[field]?.[0]" :required="['sender_organization', 'subject', 'correspondence_date', 'received_date'].includes(field)" :maxlength="field === 'subject' ? 500 : field === 'sender_phone' ? 64 : 255" />
                <TextareaInput v-for="field in descriptionFields" :id="`incoming-${field}`" :key="field" v-model="form[field]" :label="t(`incoming-${field}`)" :error-key="fields[field]?.[0]" maxlength="5000" />
            </div>
            <div class="flex gap-3"><UiButton type="submit" :loading="saving">{{ t('incomingSave') }}</UiButton><UiButton variant="secondary" :disabled="saving" @click="editing = false">{{ t('uiCancel') }}</UiButton></div>
        </form>
        <section v-else-if="detail" class="flex flex-col gap-5 rounded-2xl border border-slate-200 bg-white p-5" aria-labelledby="incoming-detail-title">
            <div class="flex flex-wrap items-center justify-between gap-3"><h2 id="incoming-detail-title" class="text-lg font-semibold">{{ detail.subject }}</h2><div v-if="detail.status === 'draft'" class="flex gap-3"><UiButton v-if="canManage" variant="secondary" :disabled="saving || loading" @click="edit()">{{ t('incomingEdit') }}</UiButton><UiButton v-if="canRegister" :disabled="saving || loading" @click="confirmation = true">{{ t('incomingRegister') }}</UiButton></div></div>
            <dl class="grid gap-4 md:grid-cols-2">
                <div><dt class="text-sm text-slate-600">{{ t('incomingNumber') }}</dt><dd class="font-mono">{{ detail.internal_number ?? '—' }}</dd></div>
                <div><dt class="text-sm text-slate-600">{{ t('uiStatus') }}</dt><dd>{{ t(`incomingStatus-${detail.status}`) }}</dd></div>
                <div><dt class="text-sm text-slate-600">{{ t('incomingType') }}</dt><dd>{{ label(detail.category) }} / {{ label(detail.type) }}</dd></div>
                <div><dt class="text-sm text-slate-600">{{ t('incomingClassification') }}</dt><dd>{{ t(`confidentiality-${detail.classification}`) }}</dd></div>
                <div><dt class="text-sm text-slate-600">{{ t('incomingPriority') }}</dt><dd>{{ t(`incomingPriority-${detail.priority}`) }}</dd></div>
                <div v-for="field in [...textFields.filter(field => field !== 'subject'), ...descriptionFields]" :key="field"><dt class="text-sm text-slate-600">{{ t(`incoming-${field}`) }}</dt><dd class="whitespace-pre-wrap wrap-anywhere">{{ detail[field] || '—' }}</dd></div>
                <div v-if="detail.registered_at"><dt class="text-sm text-slate-600">{{ t('incomingRegisteredAt') }}</dt><dd>{{ detail.registered_at }}</dd></div>
                <div v-if="detail.registered_by"><dt class="text-sm text-slate-600">{{ t('incomingRegisteredBy') }}</dt><dd>{{ detail.registered_by }}</dd></div>
            </dl>
            <p v-if="detail.status === 'registered'" class="text-sm text-slate-600">{{ t('incomingImmutable') }}</p>
            <h3 class="font-semibold">{{ t('incomingHistory') }}</h3>
            <ul class="flex flex-col gap-2"><li v-for="audit in audits" :key="audit.id">{{ t(`incomingAudit-${audit.action}`) }} · {{ audit.created_at }} · {{ t('incomingActor') }} {{ audit.actor_id }}</li></ul>
        </section>
        <ConfirmDialog v-model="confirmation" :title="t('incomingRegister')" :message="t('incomingRegisterConfirm')" :loading="saving" @confirm="register" />
        <ToastNotifications :items="items" @dismiss="dismiss" />
    </section>
</template>
