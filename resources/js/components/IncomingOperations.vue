<script setup>
import CorrespondenceCorrectionHistory from './CorrespondenceCorrectionHistory.vue';
import { computed, nextTick, onBeforeUnmount, reactive, ref } from 'vue';
import { formatDate, formatDateTime } from '../utilities/dates.js';
import UiButton from './ui/UiButton.vue';
import TextInput from './ui/TextInput.vue';
import SelectInput from './ui/SelectInput.vue';
import TextareaInput from './ui/TextareaInput.vue';
import CheckboxInput from './ui/CheckboxInput.vue';
import { useLocalization } from '../localization/index.js';
import { createJsonClient } from '../utilities/jsonClient.js';
import { showSuccess } from '../utilities/alerts.js';

const props = defineProps({ record: { type: Object, required: true }, scope: { type: Object, required: true }, endpoint: { type: String, required: true }, csrfToken: { type: String, required: true }, history: { type: Array, default: () => [] }, related: { type: Array, default: () => [] }, responsibleUser: { type: Object, default: null }, responsibleDepartment: { type: Object, default: null } });
const emit = defineEmits(['saved', 'failure', 'open-related']);
const { t, language } = useLocalization();
const request = createJsonClient(props.csrfToken);
const controller = new AbortController();
const state = computed(() => props.record.operations ?? { status: 'received', revision: 0 });
const canManage = computed(() => props.scope.permissions.includes('incoming.operations.manage'));
const canReopen = computed(() => canManage.value && props.scope.permissions.includes('incoming.operations.reopen'));
const mode = ref('');
const saving = ref(false);
const loading = ref(false);
const error = ref('');
const fields = ref({});
const form = reactive({});
const editor = ref(null);
const choices = ref({ users: [], departments: [], related: [] });
const search = ref('');
const name = item => item?.[language.value === 'lo' ? 'name_lo' : 'name_en'] ?? '';
const userOptions = computed(() => [{ value: '', label: t('incomingUnassigned') }, ...choices.value.users.map(user => ({ value: user.id, label: user.name }))]);
const departmentOptions = computed(() => [{ value: '', label: t('incomingUnassigned') }, ...choices.value.departments.map(item => ({ value: item.id, label: name(item) }))]);
const statusOptions = computed(() => ['received', 'assigned', 'in_progress', ...(state.value.response_completed ? ['responded'] : [])].map(value => ({ value, label: t(`incomingStatus-${value}`) })));
const relationOptions = computed(() => [{ value: '', label: t('uiChoose') }, ...choices.value.related.map(item => ({ value: item.id, label: `${item.internal_number} · ${item.subject}` }))]);
const scopeData = () => ({ company_id: props.scope.company_id, site_id: props.scope.site_id, department_id: props.scope.department_id });
function url(path = '', extra = {}) { return `${props.endpoint}/${props.record.id}${path}?${new URLSearchParams(Object.entries({ ...scopeData(), ...extra }).filter(([, value]) => value != null))}`; }
function failure(caught) {
    if (controller.signal.aborted) { return; }
    fields.value = caught.fields ?? {};
    error.value = Object.values(fields.value).flat().find(value => /^(incoming|rbac)/.test(value)) ?? (/^(incoming|rbac)/.test(caught.message) ? caught.message : 'rbacRequestFailed');
    if ([401, 403, 404, 419].includes(caught.status)) { emit('failure', caught); }
}
async function options() {
    loading.value = true;
    try { const result = await request(url('/operation-options', { search: search.value }), { signal: controller.signal }); if (!controller.signal.aborted) { choices.value = result; } }
    catch (caught) { failure(caught); }
    finally { if (!controller.signal.aborted) { loading.value = false; } }
}
async function start(action) {
    mode.value = action; error.value = ''; fields.value = {}; search.value = ''; choices.value.related = [];
    Object.assign(form, { responsible_user_id: state.value.responsible_user_id ?? '', responsible_department_id: state.value.responsible_department_id ?? '', due_date: state.value.due_date ?? '', response_required: state.value.response_required ?? false, response_completed: state.value.response_completed ?? false, status: state.value.status, note: '', related_id: '' });
    await nextTick(); editor.value?.querySelector('h3')?.focus();
    if (action === 'update' || action === 'link') { await options(); }
}
async function save(action = mode.value, relatedId = null) {
    if (saving.value || loading.value) { return; }
    const body = { ...scopeData(), action, revision: state.value.revision, note: action === 'unlink' ? null : form.note || null };
    if (action === 'update') {
        Object.assign(body, { responsible_user_id: form.responsible_user_id || null, responsible_department_id: form.responsible_department_id || null, due_date: form.due_date || null, response_required: form.response_required, response_completed: form.response_completed });
        if (form.status !== state.value.status) { body.status = form.status; }
    }
    if (action === 'link' || action === 'unlink') { body.related_id = relatedId ?? form.related_id; }
    saving.value = true; error.value = ''; fields.value = {};
    try {
        const result = await request(url('/operations'), { method: 'POST', body, signal: controller.signal });
        if (!controller.signal.aborted) { mode.value = ''; showSuccess(t('uiSaveSuccess')); emit('saved', result.data); }
    } catch (caught) { failure(caught); await nextTick(); editor.value?.querySelector('[aria-invalid="true"]')?.focus(); }
    finally { if (!controller.signal.aborted) { saving.value = false; } }
}
function display(field, value) {
    if (value === null || value === '') { return '—'; }
    if (typeof value === 'boolean') { return t(value ? 'incomingYes' : 'incomingNo'); }
    if (field === 'status') { return t(`incomingStatus-${value}`); }
    if (typeof value === 'object') { return name(value); }
    if (field.endsWith('_date')) { return formatDate(value); }
    if (field.endsWith('_at')) { return formatDateTime(value); }
    return value;
}
onBeforeUnmount(() => controller.abort());
</script>

<template>
    <section class="flex flex-col gap-4 border-t border-slate-200 pt-5" aria-labelledby="incoming-operations-title">
        <h2 id="incoming-operations-title" class="text-lg font-semibold">{{ t('incomingHandling') }}</h2>
        <dl class="grid gap-4 md:grid-cols-2">
            <div><dt class="text-sm text-slate-600">{{ t('uiStatus') }}</dt><dd>{{ t(`incomingStatus-${state.status}`) }}</dd></div>
            <div><dt class="text-sm text-slate-600">{{ t('incomingResponsible') }}</dt><dd>{{ responsibleUser?.name || t('incomingUnassigned') }}<span v-if="responsibleDepartment"> · {{ name(responsibleDepartment) }}</span></dd></div>
            <div><dt class="text-sm text-slate-600">{{ t('incomingDueDate') }}</dt><dd>{{ formatDate(state.due_date) }}<span v-if="record.due_indicator" class="ml-2 font-semibold" :class="record.due_indicator === 'overdue' ? 'text-red-700' : 'text-slate-700'">{{ t(`incomingDue-${record.due_indicator}`) }}</span></dd></div>
            <div><dt class="text-sm text-slate-600">{{ t('incomingResponse') }}</dt><dd>{{ t(state.response_completed ? 'incomingResponseCompleted' : state.response_required ? 'incomingResponsePendingLabel' : 'incomingResponseNotRequired') }}</dd></div>
            <div v-if="state.closed_at"><dt class="text-sm text-slate-600">{{ t('incomingClosedAt') }}</dt><dd>{{ formatDateTime(state.closed_at) }}</dd></div>
            <div v-if="state.closure_remarks"><dt class="text-sm text-slate-600">{{ t('incomingClosureRemarks') }}</dt><dd class="whitespace-pre-wrap">{{ state.closure_remarks }}</dd></div>
        </dl>
        <div v-if="canManage && !mode" class="flex flex-wrap gap-2 print:hidden">
            <template v-if="state.status !== 'closed'">
                <UiButton variant="secondary" :disabled="saving" @click="start('update')">{{ t('incomingUpdateHandling') }}</UiButton>
                <UiButton variant="secondary" :disabled="saving" @click="start('note')">{{ t('incomingFollowUp') }}</UiButton>
                <UiButton variant="secondary" :disabled="saving" @click="start('link')">{{ t('incomingLink') }}</UiButton>
                <UiButton variant="secondary" :disabled="saving" @click="start('close')">{{ t('incomingClose') }}</UiButton>
            </template>
            <UiButton v-else-if="canReopen" variant="secondary" :disabled="saving" @click="start('reopen')">{{ t('incomingReopen') }}</UiButton>
        </div>
        <form v-if="mode" ref="editor" class="flex flex-col gap-4 rounded-xl bg-slate-50 p-4 print:hidden" @submit.prevent="save()">
            <h3 tabindex="-1" class="font-semibold">{{ t(`incomingAction-${mode}`) }}</h3>
            <p v-if="error" role="alert" class="text-red-800">{{ t(error) }}</p>
            <p v-if="mode === 'close' || mode === 'reopen'" class="text-sm text-slate-600">{{ t(mode === 'close' ? 'incomingCloseHelp' : 'incomingReopenHelp') }}</p>
            <div v-if="mode === 'update'" class="grid gap-4 md:grid-cols-2">
                <SelectInput id="incoming-responsible-user" v-model="form.responsible_user_id" :label="t('incomingResponsibleUser')" :options="userOptions" :error-key="fields.responsible_user_id?.[0]" :disabled="loading || saving" />
                <SelectInput id="incoming-responsible-department" v-model="form.responsible_department_id" :label="t('incomingResponsibleDepartment')" :options="departmentOptions" :error-key="fields.responsible_department_id?.[0]" :disabled="loading || saving" />
                <SelectInput id="incoming-progress" v-model="form.status" :label="t('uiStatus')" :options="statusOptions" :error-key="fields.status?.[0]" :disabled="saving" />
                <TextInput id="incoming-due-date" v-model="form.due_date" type="date" allow-future :label="t('incomingDueDate')" :error-key="fields.due_date?.[0]" :disabled="saving" />
                <CheckboxInput id="incoming-response-required" v-model="form.response_required" :label="t('incomingResponseRequired')" :disabled="saving" />
                <CheckboxInput id="incoming-response-completed" v-model="form.response_completed" :label="t('incomingResponseCompleted')" :disabled="saving || !form.response_required" />
            </div>
            <div v-if="mode === 'link'" class="flex flex-col gap-3">
                <TextInput id="incoming-related-search" v-model="search" :label="t('incomingRelatedSearch')" :disabled="saving" />
                <UiButton variant="secondary" :loading="loading" :disabled="saving || !search.trim()" @click="options">{{ t('incomingFind') }}</UiButton>
                <SelectInput id="incoming-related" v-model="form.related_id" :label="t('incomingRelated')" :options="relationOptions" :error-key="fields.related_id?.[0]" required :disabled="saving || loading" />
            </div>
            <TextareaInput id="incoming-operation-note" v-model="form.note" :label="t(mode === 'close' ? 'incomingClosureRemarks' : mode === 'reopen' ? 'incomingReopenReason' : 'incomingNote')" :helper="mode === 'update' ? t('incomingCorrectionHelp') : ''" :error-key="fields.note?.[0]" :required="['note', 'reopen'].includes(mode)" maxlength="2000" :disabled="saving" />
            <div class="flex gap-2"><UiButton type="submit" :loading="saving" :disabled="loading">{{ t(mode === 'close' ? 'incomingClose' : mode === 'reopen' ? 'incomingReopen' : 'incomingSaveHandling') }}</UiButton><UiButton variant="secondary" :disabled="saving" @click="mode = ''">{{ t('uiCancel') }}</UiButton></div>
        </form>
        <p v-if="error && !mode" role="alert" class="text-red-800">{{ t(error) }}</p>
        <h3 class="font-semibold">{{ t('incomingRelated') }}</h3>
        <p v-if="!related.length" class="text-sm text-slate-600">{{ t('incomingNoRelated') }}</p>
        <ul v-else class="flex flex-col gap-2">
            <li v-for="item in related" :key="item.id" class="flex flex-wrap items-center gap-2"><UiButton variant="secondary" :disabled="saving || !!mode" @click="emit('open-related', item)">{{ item.internal_number }} · {{ item.subject }}</UiButton><UiButton v-if="canManage && state.status !== 'closed'" variant="secondary" :disabled="saving || !!mode" @click="save('unlink', item.id)">{{ t('incomingUnlink') }}</UiButton></li>
        </ul>
        <h3 class="font-semibold">{{ t('incomingOperationalHistory') }}</h3>
        <p v-if="!history.length" class="text-sm text-slate-600">{{ t('incomingNoActions') }}</p>
        <ol class="flex flex-col gap-3">
            <li v-for="item in history" :key="item.id" class="rounded-xl border border-slate-200 p-3">
                <p class="font-medium">{{ t(item.after?.details ? 'correspondenceCorrectDetails' : `incomingOperation-${item.action}`) }} · {{ item.actor_name }} · {{ formatDateTime(item.created_at) }}</p>
                <p v-if="item.note" class="whitespace-pre-wrap">{{ item.note }}</p>
                <CorrespondenceCorrectionHistory v-if="item.after?.details" :item="item" kind="incoming" /><dl v-else class="text-sm text-slate-600"><div v-for="field in [...new Set([...Object.keys(item.before), ...Object.keys(item.after)])]" :key="field"><dt class="inline font-medium">{{ t(`incomingField-${field}`) }}: </dt><dd class="inline">{{ display(field, item.before[field] ?? null) }} → {{ display(field, item.after[field] ?? null) }}</dd></div></dl>
            </li>
        </ol>
    </section>
</template>
