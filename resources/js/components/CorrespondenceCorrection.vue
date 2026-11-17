<script setup>
import { computed, nextTick, onBeforeUnmount, reactive, ref } from 'vue';
import { useLocalization } from '../localization/index.js';
import { createJsonClient } from '../utilities/jsonClient.js';
import { showSuccess } from '../utilities/alerts.js';
import TextInput from './ui/TextInput.vue';
import TextareaInput from './ui/TextareaInput.vue';
import SelectInput from './ui/SelectInput.vue';
import UiButton from './ui/UiButton.vue';

const props = defineProps({ kind: { type: String, required: true }, record: { type: Object, required: true }, scope: { type: Object, required: true }, endpoint: { type: String, required: true }, csrfToken: { type: String, required: true }, history: { type: Array, default: () => [] } });
const emit = defineEmits(['saved', 'failure']);
const { t } = useLocalization();
const outgoing = computed(() => props.kind === 'outgoing');
const allowed = computed(() => props.record.status === 'registered' && props.scope.permissions.includes(`${props.kind}.operations.manage`)
    && props.record.operations?.status !== 'closed' && (!outgoing.value || ((!props.record.operations || props.record.operations.status === 'registered')
    && !props.record.operations?.dispatch_date && !props.record.recipients?.some(item => item.delivery)
    && !props.history.some(item => ['dispatch', 'correct_dispatch', 'delivery'].includes(item.action)))));
const mainFields = computed(() => outgoing.value ? ['subject', 'document_date', 'signatory_name'] : ['subject', 'sender_organization', 'external_reference', 'correspondence_date', 'received_date']);
const extraFields = computed(() => outgoing.value ? ['document_revision', 'signatory_title', 'description_en', 'description_lo'] : ['document_revision', 'sender_contact', 'sender_email', 'sender_phone', 'description_en', 'description_lo']);
const recipientFields = ['organization', 'contact_name', 'email', 'phone', 'address'];
const editing = ref(false);
const saving = ref(false);
const revision = ref(0);
const form = reactive({});
const reason = ref('');
const fields = ref({});
const error = ref('');
const editor = ref(null);
const controller = new AbortController();
const request = createJsonClient(props.csrfToken);
const channels = computed(() => ['email', 'courier', 'hand_delivery', 'post', 'other'].map(value => ({ value, label: t(`incomingChannel-${value}`) })));
const priorities = computed(() => ['normal', 'urgent'].map(value => ({ value, label: t(`incomingPriority-${value}`) })));
const required = field => ['subject', 'sender_organization', 'correspondence_date', 'received_date', 'document_date', 'signatory_name'].includes(field);
const inputType = field => field.endsWith('_date') ? 'date' : field.endsWith('email') ? 'email' : 'text';
const maximum = field => field === 'document_revision' ? 30 : field === 'subject' || field === 'address' ? 500 : field.endsWith('phone') ? 64 : 255;
async function start() {
    Object.keys(form).forEach(key => delete form[key]);
    for (const field of [...mainFields.value, ...extraFields.value, 'priority', ...(!outgoing.value ? ['channel'] : [])]) { form[field] = props.record[field] ?? ''; }
    if (outgoing.value) { form.recipients = props.record.recipients.map(item => Object.fromEntries(['id', ...recipientFields].map(field => [field, item[field] ?? '']))); }
    revision.value = props.record.operations?.revision ?? 0;
    reason.value = ''; fields.value = {}; error.value = ''; editing.value = true;
    await nextTick(); editor.value?.querySelector('h3')?.focus();
}
async function save() {
    if (saving.value) { return; }
    saving.value = true; fields.value = {}; error.value = '';
    const details = Object.fromEntries(Object.entries(form).map(([key, value]) => [key, value === '' ? null : value]));
    if (outgoing.value) { details.recipients = form.recipients.map(item => Object.fromEntries(Object.entries(item).map(([key, value]) => [key, value === '' ? null : value]))); }
    const scope = Object.fromEntries(['company_id', 'site_id', 'department_id'].map(key => [key, props.scope[key]]).filter(([, value]) => value != null));
    try {
        const result = await request(`${props.endpoint}/${props.record.id}/operations?${new URLSearchParams(scope)}`, { method: 'POST', body: { ...scope, action: 'correct_details', revision: revision.value, note: reason.value, details }, signal: controller.signal });
        if (!controller.signal.aborted) { editing.value = false; showSuccess(t('uiSaveSuccess')); emit('saved', result.data); }
    } catch (caught) {
        if (!controller.signal.aborted) {
            fields.value = caught.fields ?? {};
            const key = Object.values(fields.value).flat()[0] ?? caught.message;
            error.value = /^(incoming|outgoing|correspondence|rbac)/.test(key) ? key : 'rbacRequestFailed';
            if ([401, 403, 404, 419].includes(caught.status)) { emit('failure', caught); }
            await nextTick(); editor.value?.querySelector('[aria-invalid="true"]')?.focus();
        }
    } finally { if (!controller.signal.aborted) { saving.value = false; } }
}
onBeforeUnmount(() => controller.abort());
</script>

<template>
    <div v-if="allowed" class="flex flex-col gap-3 print:hidden">
        <UiButton v-if="!editing" variant="secondary" class="self-start" @click="start">{{ t('correspondenceCorrectDetails') }}</UiButton>
        <form v-else ref="editor" class="flex flex-col gap-4 rounded-xl border border-slate-200 bg-slate-50 p-4" @submit.prevent="save">
            <h3 tabindex="-1" class="font-semibold">{{ t('correspondenceCorrectDetails') }}</h3>
            <p class="text-sm text-slate-600">{{ t(outgoing ? 'correspondenceOutgoingCorrectionHelp' : 'correspondenceIncomingCorrectionHelp') }}</p>
            <p class="text-sm text-slate-600">{{ t('correspondenceProtectedIdentity') }} · {{ record.internal_number }}<span v-if="outgoing"> · {{ record.official_number }}</span></p>
            <p v-if="error" role="alert" class="text-red-800">{{ t(error) }}</p>
            <fieldset :disabled="saving" class="grid gap-4 md:grid-cols-2">
                <TextInput v-for="field in mainFields" :id="`${kind}-correction-${field}`" :key="field" v-model="form[field]" :label="t(`${kind}-${field}`)" :type="inputType(field)" :helper="field === 'received_date' ? t('correspondenceReceivedDateHelp') : field.endsWith('_date') ? t('correspondenceDocumentDateHelp') : ''" :required="required(field)" :maxlength="maximum(field)" :error-key="fields[`details.${field}`]?.[0]" />
                <SelectInput :id="`${kind}-correction-priority`" v-model="form.priority" :label="t(`${kind}Priority`)" :options="priorities" :error-key="fields['details.priority']?.[0]" required />
                <SelectInput v-if="!outgoing" id="incoming-correction-channel" v-model="form.channel" :label="t('incomingChannel')" :options="channels" :error-key="fields['details.channel']?.[0]" required />
            </fieldset>
            <details :open="extraFields.some(field => fields[`details.${field}`])" class="rounded-xl border border-slate-200 p-3">
                <summary class="cursor-pointer text-sm font-semibold">{{ t('additionalInformation') }}</summary>
                <fieldset :disabled="saving" class="mt-3 grid gap-4 md:grid-cols-2">
                    <template v-for="field in extraFields" :key="field">
                        <TextareaInput v-if="field.startsWith('description_')" :id="`${kind}-correction-${field}`" v-model="form[field]" :label="t(`${kind}-${field}`)" :error-key="fields[`details.${field}`]?.[0]" maxlength="5000" />
                        <TextInput v-else :id="`${kind}-correction-${field}`" v-model="form[field]" :label="t(`${kind}-${field}`)" :type="inputType(field)" :maxlength="maximum(field)" :error-key="fields[`details.${field}`]?.[0]" />
                    </template>
                </fieldset>
            </details>
            <details v-if="outgoing" :open="Object.keys(fields).some(field => field.startsWith('details.recipients'))" class="rounded-xl border border-slate-200 p-3">
                <summary class="cursor-pointer text-sm font-semibold">{{ t('outgoingRecipients') }}</summary>
                <fieldset v-for="(recipient, index) in form.recipients" :key="recipient.id" :disabled="saving" class="mt-3 grid gap-4 md:grid-cols-2">
                    <legend class="font-medium">{{ t(`outgoingRecipient-${record.recipients[index].kind}`) }} · {{ record.recipients[index].organization }}</legend>
                    <TextInput v-for="field in recipientFields" :id="`outgoing-correction-recipient-${index}-${field}`" :key="field" v-model="recipient[field]" :label="t(`outgoingRecipient-${field}`)" :type="inputType(field)" :helper="field === 'received_date' ? t('correspondenceReceivedDateHelp') : field.endsWith('_date') ? t('correspondenceDocumentDateHelp') : ''" :required="field === 'organization'" :maxlength="maximum(field)" :error-key="fields[`details.recipients.${index}.${field}`]?.[0]" />
                </fieldset>
            </details>
            <TextareaInput :id="`${kind}-correction-reason`" v-model="reason" :label="t('correspondenceCorrectionReason')" :helper="t('correspondenceCorrectionReasonHelp')" :error-key="fields.note?.[0]" :disabled="saving" required minlength="10" maxlength="2000" />
            <div class="flex gap-2"><UiButton type="submit" :loading="saving">{{ t('correspondenceSaveCorrection') }}</UiButton><UiButton variant="secondary" :disabled="saving" @click="editing = false">{{ t('uiCancel') }}</UiButton></div>
        </form>
    </div>
    <p v-else-if="outgoing && record.status === 'registered' && scope.permissions.includes('outgoing.operations.manage') && record.operations?.status !== 'closed'" class="text-sm text-slate-600">{{ t('outgoingDetailsAfterDispatch') }}</p>
</template>
