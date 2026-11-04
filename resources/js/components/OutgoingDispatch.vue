<script setup>
import CorrespondenceCorrectionHistory from './CorrespondenceCorrectionHistory.vue';
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { formatDate, formatDateTime } from '../utilities/dates.js';
import UiButton from './ui/UiButton.vue';
import TextInput from './ui/TextInput.vue';
import SelectInput from './ui/SelectInput.vue';
import TextareaInput from './ui/TextareaInput.vue';
import { useLocalization } from '../localization/index.js';
import { createJsonClient } from '../utilities/jsonClient.js';

const props = defineProps({ record: { type: Object, required: true }, scope: { type: Object, required: true }, endpoint: { type: String, required: true }, incomingEndpoint: { type: String, default: '' }, csrfToken: { type: String, required: true }, history: { type: Array, default: () => [] }, relatedIncoming: { type: Array, default: () => [] } });
const emit = defineEmits(['saved', 'failure']);
const { t } = useLocalization();
const request = createJsonClient(props.csrfToken);
const controller = new AbortController();
const state = computed(() => props.record.operations ?? { status: 'registered', revision: 0 });
const canManage = computed(() => props.scope.permissions.includes('outgoing.operations.manage'));
const canReopen = computed(() => canManage.value && props.scope.permissions.includes('outgoing.operations.reopen'));
const mode = ref('');
const saving = ref(false);
const loading = ref(false);
const error = ref('');
const fields = ref({});
const form = reactive({});
const editor = ref(null);
const search = ref('');
const choices = ref([]);
const incomingDetail = ref(null);
const channels = computed(() => [{ value: '', label: t('uiChoose') }, ...['email', 'courier', 'hand_delivery', 'post', 'other'].map(value => ({ value, label: t(`outgoingChannel-${value}`) }))]);
const recipients = computed(() => [{ value: '', label: t('uiChoose') }, ...props.record.recipients.map(item => ({ value: item.id, label: `${t(`outgoingRecipient-${item.kind}`)} · ${item.organization}` }))]);
const incomingOptions = computed(() => [{ value: '', label: t('uiChoose') }, ...choices.value.map(item => ({ value: item.id, label: `${item.internal_number} · ${item.subject}` }))]);
const scopeData = () => ({ company_id: props.scope.company_id, site_id: props.scope.site_id, department_id: props.scope.department_id });
function query(extra = {}) { return new URLSearchParams(Object.entries({ ...scopeData(), ...extra }).filter(([, value]) => value != null)); }
function url(path, extra = {}) { return `${props.endpoint}/${props.record.id}${path}?${query(extra)}`; }
function failure(caught) {
    if (controller.signal.aborted) { return; }
    fields.value = caught.fields ?? {};
    error.value = Object.values(fields.value).flat().find(value => /^(outgoing|incoming|rbac)/.test(value)) ?? (/^(outgoing|incoming|rbac)/.test(caught.message) ? caught.message : 'rbacRequestFailed');
    if ([401, 403, 404, 419].includes(caught.status)) { emit('failure', caught); }
}
function loadDelivery() {
    const recipient = props.record.recipients.find(item => item.id === Number(form.recipient_id));
    Object.assign(form, { delivery_date: recipient?.delivery?.delivery_date ?? '', acknowledged_by: recipient?.delivery?.acknowledged_by ?? '', acknowledgment_date: recipient?.delivery?.acknowledgment_date ?? '', acknowledgment_reference: recipient?.delivery?.acknowledgment_reference ?? '' });
}
watch(() => form.recipient_id, loadDelivery);
async function start(action) {
    mode.value = action; error.value = ''; fields.value = {}; choices.value = []; search.value = '';
    Object.assign(form, { dispatch_date: state.value.dispatch_date ?? '', dispatch_channel: state.value.dispatch_channel ?? '', dispatch_reference: state.value.dispatch_reference ?? '', dispatch_remarks: state.value.dispatch_remarks ?? '', recipient_id: '', incoming_id: '', note: '' });
    loadDelivery();
    await nextTick(); editor.value?.querySelector('h3')?.focus();
}
async function findIncoming() {
    loading.value = true;
    try { const result = await request(url('/operation-options', { search: search.value }), { signal: controller.signal }); if (!controller.signal.aborted) { choices.value = result.data; } }
    catch (caught) { failure(caught); }
    finally { if (!controller.signal.aborted) { loading.value = false; } }
}
async function openIncoming(item) {
    incomingDetail.value = null; error.value = ''; loading.value = true;
    try {
        const result = await request(`${props.incomingEndpoint}/${item.id}?${query()}`, { signal: controller.signal });
        if (!controller.signal.aborted) { incomingDetail.value = result.data; }
    } catch (caught) {
        if (caught.status === 404 && !controller.signal.aborted) { emit('saved', props.record); }
        else { failure(caught); }
    } finally { if (!controller.signal.aborted) { loading.value = false; } }
}
async function save(action = mode.value, incomingId = null) {
    if (saving.value || loading.value) { return; }
    const body = { ...scopeData(), action, revision: state.value.revision, note: action === 'unlink' ? null : form.note || null };
    if (action === 'dispatch' || action === 'correct_dispatch') { Object.assign(body, { dispatch_date: form.dispatch_date || null, dispatch_channel: form.dispatch_date ? form.dispatch_channel : null, dispatch_reference: form.dispatch_date ? form.dispatch_reference || null : null, dispatch_remarks: form.dispatch_date ? form.dispatch_remarks || null : null }); }
    if (action === 'delivery') { Object.assign(body, { recipient_id: form.recipient_id, delivery_date: form.delivery_date || null, acknowledged_by: form.delivery_date ? form.acknowledged_by || null : null, acknowledgment_date: form.delivery_date ? form.acknowledgment_date || null : null, acknowledgment_reference: form.delivery_date ? form.acknowledgment_reference || null : null }); }
    if (action === 'link' || action === 'unlink') { body.incoming_id = incomingId ?? form.incoming_id; }
    saving.value = true; error.value = ''; fields.value = {};
    try {
        const result = await request(url('/operations'), { method: 'POST', body, signal: controller.signal });
        if (!controller.signal.aborted) { mode.value = ''; emit('saved', result.data); }
    } catch (caught) { failure(caught); await nextTick(); editor.value?.querySelector('[aria-invalid="true"]')?.focus(); }
    finally { if (!controller.signal.aborted) { saving.value = false; } }
}
function changes(item) {
    const before = { ...item.before, ...item.before.delivery };
    const after = { ...item.after, ...item.after.delivery };
    return [...new Set([...Object.keys(before), ...Object.keys(after)])].filter(field => field !== 'delivery').map(field => ({ field, before: before[field], after: after[field] }));
}
function display(field, value) {
    if (value == null || value === '') { return '—'; }
    if (field === 'status') { return t(`outgoingStatus-${value}`); }
    if (field === 'dispatch_channel') { return t(`outgoingChannel-${value}`); }
    if (field.endsWith('_at')) { return formatDateTime(value); }
    if (field.endsWith('_date')) { return formatDate(value); }
    return value;
}
onBeforeUnmount(() => controller.abort());
</script>

<template>
    <section class="flex flex-col gap-4 border-t border-slate-200 pt-5" aria-labelledby="outgoing-dispatch-title">
        <h2 id="outgoing-dispatch-title" class="text-lg font-semibold">{{ t('outgoingDispatchHandling') }}</h2>
        <p class="text-sm text-slate-600">{{ t('outgoingDispatchRules') }}</p>
        <dl class="grid gap-4 md:grid-cols-2">
            <div><dt class="text-sm text-slate-600">{{ t('uiStatus') }}</dt><dd>{{ t(`outgoingStatus-${state.status}`) }}</dd></div>
            <div v-for="field in ['dispatch_date', 'dispatch_channel', 'dispatch_reference', 'dispatch_remarks', 'closed_at', 'closure_remarks']" :key="field"><dt class="text-sm text-slate-600">{{ t(`outgoingField-${field}`) }}</dt><dd class="whitespace-pre-wrap wrap-anywhere">{{ display(field, state[field]) }}</dd></div>
        </dl>
        <div v-if="canManage && !mode" class="flex flex-wrap gap-2">
            <template v-if="state.status !== 'closed'">
                <UiButton v-if="state.status === 'registered'" variant="secondary" :disabled="saving || loading" @click="start('dispatch')">{{ t('outgoingAction-dispatch') }}</UiButton>
                <template v-else><UiButton variant="secondary" :disabled="saving || loading" @click="start('delivery')">{{ t('outgoingAction-delivery') }}</UiButton><UiButton variant="secondary" :disabled="saving || loading" @click="start('correct_dispatch')">{{ t('outgoingAction-correct_dispatch') }}</UiButton></template>
                <UiButton v-for="action in ['note', 'link', 'close']" :key="action" variant="secondary" :disabled="saving || loading" @click="start(action)">{{ t(`outgoingAction-${action}`) }}</UiButton>
            </template>
            <UiButton v-else-if="canReopen" variant="secondary" :disabled="saving || loading" @click="start('reopen')">{{ t('outgoingAction-reopen') }}</UiButton>
        </div>
        <p v-if="error" role="alert" class="text-red-800">{{ t(error) }}</p>
        <form v-if="mode" ref="editor" class="flex flex-col gap-4 rounded-xl bg-slate-50 p-4" @submit.prevent="save()">
            <h3 tabindex="-1" class="font-semibold">{{ t(`outgoingAction-${mode}`) }}</h3>
            <p v-if="mode === 'correct_dispatch'" class="text-sm text-slate-600">{{ t('outgoingUndoDispatchHelp') }}</p>
            <fieldset v-if="['dispatch', 'correct_dispatch'].includes(mode)" :disabled="saving" class="grid gap-4 md:grid-cols-2">
                <TextInput id="outgoing-dispatch-date" :helper="t('correspondenceDispatchDateHelp')" v-model="form.dispatch_date" type="date" :label="t('outgoingField-dispatch_date')" :error-key="fields.dispatch_date?.[0]" :required="mode === 'dispatch'" />
                <SelectInput id="outgoing-dispatch-channel" v-model="form.dispatch_channel" :label="t('outgoingField-dispatch_channel')" :options="channels" :error-key="fields.dispatch_channel?.[0]" :required="mode === 'dispatch' || !!form.dispatch_date" />
                <TextInput id="outgoing-dispatch-reference" v-model="form.dispatch_reference" :label="t('outgoingField-dispatch_reference')" :error-key="fields.dispatch_reference?.[0]" maxlength="255" />
                <TextareaInput id="outgoing-dispatch-remarks" v-model="form.dispatch_remarks" :label="t('outgoingField-dispatch_remarks')" :error-key="fields.dispatch_remarks?.[0]" maxlength="2000" />
            </fieldset>
            <fieldset v-if="mode === 'delivery'" :disabled="saving" class="grid gap-4 md:grid-cols-2">
                <SelectInput id="outgoing-delivery-recipient" v-model="form.recipient_id" :label="t('outgoingRecipient')" :options="recipients" :error-key="fields.recipient_id?.[0]" required />
                <TextInput id="outgoing-delivery-date" v-model="form.delivery_date" type="date" :label="t('outgoingField-delivery_date')" :helper="t('outgoingDeliveryHelp')" :error-key="fields.delivery_date?.[0]" />
                <TextInput id="outgoing-acknowledged-by" v-model="form.acknowledged_by" :label="t('outgoingField-acknowledged_by')" :error-key="fields.acknowledged_by?.[0]" maxlength="255" />
                <TextInput id="outgoing-acknowledgment-date" v-model="form.acknowledgment_date" type="date" :label="t('outgoingField-acknowledgment_date')" :error-key="fields.acknowledgment_date?.[0]" />
                <TextInput id="outgoing-acknowledgment-reference" v-model="form.acknowledgment_reference" :label="t('outgoingField-acknowledgment_reference')" :error-key="fields.acknowledgment_reference?.[0]" maxlength="255" />
            </fieldset>
            <div v-if="mode === 'link'" class="flex flex-col gap-3">
                <TextInput id="outgoing-incoming-search" v-model="search" :label="t('outgoingIncomingSearch')" :disabled="saving" maxlength="200" />
                <UiButton variant="secondary" :loading="loading" :disabled="saving || !search.trim()" @click="findIncoming">{{ t('outgoingFindIncoming') }}</UiButton>
                <SelectInput id="outgoing-related-incoming" v-model="form.incoming_id" :label="t('outgoingRelatedIncoming')" :options="incomingOptions" :error-key="fields.incoming_id?.[0]" :disabled="saving || loading" required />
            </div>
            <p v-if="mode === 'close'" class="text-sm text-slate-600">{{ t('outgoingCloseHelp') }}</p>
            <TextareaInput id="outgoing-operation-note" v-model="form.note" :label="t('outgoingOperationNote')" :helper="t('outgoingCorrectionHelp')" :error-key="fields.note?.[0]" :required="['reopen', 'note', 'correct_dispatch'].includes(mode) || (mode === 'close' && state.status === 'registered')" :disabled="saving" maxlength="2000" />
            <div class="flex gap-2"><UiButton type="submit" :loading="saving" :disabled="loading">{{ t('outgoingSaveOperation') }}</UiButton><UiButton variant="secondary" :disabled="saving" @click="mode = ''">{{ t('uiCancel') }}</UiButton></div>
        </form>
        <h3 class="font-semibold">{{ t('outgoingDeliveryTracking') }}</h3>
        <ul class="flex flex-col gap-3"><li v-for="recipient in record.recipients" :key="recipient.id" class="rounded-xl border border-slate-200 p-3"><p class="font-medium">{{ recipient.organization }} · {{ t(recipient.delivery?.delivery_date ? 'outgoingStatus-delivered' : 'outgoingDeliveryPending') }}</p><dl><div v-for="field in ['delivery_date', 'acknowledged_by', 'acknowledgment_date', 'acknowledgment_reference'].filter(field => recipient.delivery?.[field])" :key="field"><dt class="inline text-sm text-slate-600">{{ t(`outgoingField-${field}`) }}: </dt><dd class="inline">{{ field.endsWith('_date') ? formatDate(recipient.delivery[field]) : recipient.delivery[field] }}</dd></div></dl></li></ul>
        <h3 class="font-semibold">{{ t('outgoingRelatedIncoming') }}</h3>
        <p v-if="!relatedIncoming.length" class="text-sm text-slate-600">{{ t('outgoingNoRelatedIncoming') }}</p>
        <ul class="flex flex-col gap-2"><li v-for="item in relatedIncoming" :key="item.id" class="flex flex-wrap gap-2"><UiButton v-if="incomingEndpoint" variant="secondary" :disabled="saving || loading" @click="openIncoming(item)">{{ item.internal_number }} · {{ item.subject }}</UiButton><span v-else>{{ item.internal_number }} · {{ item.subject }}</span><UiButton v-if="canManage && state.status !== 'closed'" variant="secondary" :disabled="saving || loading || !!mode" @click="save('unlink', item.id)">{{ t('outgoingRemoveIncomingLink') }}</UiButton></li></ul>
        <section v-if="incomingDetail" class="rounded-xl border border-slate-200 p-4" :aria-label="t('outgoingRelatedIncoming')"><h3 class="font-semibold">{{ incomingDetail.internal_number }} · {{ incomingDetail.subject }}</h3><p>{{ incomingDetail.sender_organization }} · {{ formatDate(incomingDetail.received_date) }}</p><p>{{ t(`confidentiality-${incomingDetail.classification}`) }}</p><UiButton variant="secondary" @click="incomingDetail = null">{{ t('outgoingClosePreview') }}</UiButton></section>
        <h3 class="font-semibold">{{ t('outgoingOperationalHistory') }}</h3>
        <p v-if="!history.length" class="text-sm text-slate-600">{{ t('outgoingNoOperations') }}</p>
        <ol class="flex flex-col gap-3"><li v-for="item in history" :key="item.id" class="rounded-xl border border-slate-200 p-3"><p class="font-medium">{{ t(item.after?.details ? 'correspondenceCorrectDetails' : `outgoingAction-${item.action}`) }} · {{ item.actor_name }} · {{ formatDateTime(item.created_at) }}</p><p v-if="item.note" class="whitespace-pre-wrap">{{ item.note }}</p><CorrespondenceCorrectionHistory v-if="item.after?.details" :item="item" kind="outgoing" /><dl v-else class="text-sm text-slate-600"><div v-for="change in changes(item)" :key="change.field"><dt class="inline font-medium">{{ t(`outgoingField-${change.field}`) }}: </dt><dd class="inline">{{ display(change.field, change.before) }} → {{ display(change.field, change.after) }}</dd></div></dl></li></ol>
    </section>
</template>
