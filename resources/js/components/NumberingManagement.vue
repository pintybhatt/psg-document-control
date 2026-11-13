<script setup>
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue';
import UiButton from './ui/UiButton.vue';
import IconButton from './ui/IconButton.vue';
import TextInput from './ui/TextInput.vue';
import SelectInput from './ui/SelectInput.vue';
import CheckboxInput from './ui/CheckboxInput.vue';
import DataTable from './ui/DataTable.vue';
import StatusBadge from './ui/StatusBadge.vue';
import ConfirmDialog from './ui/SweetConfirmDialog.vue';
import FormModal from './ui/FormModal.vue';
import { showSuccess } from '../utilities/alerts.js';
import { useLocalization } from '../localization/index.js';
import { formatDateTime } from '../utilities/dates.js';
import { createJsonClient } from '../utilities/jsonClient.js';

const props = defineProps({ scope: { type: Object, required: true }, endpoint: { type: String, required: true }, csrfToken: { type: String, required: true } });
const emit = defineEmits(['unauthorized']);
const { t, language } = useLocalization();
const request = createJsonClient(props.csrfToken);
const rows = ref([]);
const registers = ref([]);
const page = ref(1);
const total = ref(0);
const loading = ref(false);
const saving = ref(false);
const previewLoading = ref(false);
const preview = ref('');
const editor = ref(null);
const editorElement = ref(null);
const revoked = ref(false);
const errorKey = ref('');
const fields = ref({});
const confirmationOpen = ref(false);
const historySeries = ref(null);
const history = ref([]);
const historyPage = ref(1);
const historyTotal = ref(0);
const historyLoading = ref(false);
const form = reactive({ register_id: '', purpose: 'internal_register', reset_policy: 'annual', prefix: '', padding: '6', active: true, change_reason: '', year: String(new Date().getFullYear()), custom_format: false, pattern: '', company: '', department: '', document_type: '' });
const xpplOptions = ref({ departments: [], types: [], series: [], history: [], mappings: [] });
const xpplKind = ref('');
const xpplKinds = computed(() => [{ value: '', label: t('uiChoose') }, ...['xppl_controlled', 'xppl_letter'].map(value => ({ value, label: t(`numberingFormat-${value}`) }))]);
const xpplForm = reactive({ series_id: '', department_id: '', type_id: '' });
const reconciliation = ref(null);
const cutoverConfirmed = ref(false);
const xpplBusy = ref(false);
const policyConfirmed = ref(false);
const registerPolicyConfirmed = reactive({ incoming: false, outgoing: false });
async function activatePolicy(kind = 'controlled') {
    if (!canManage.value || !(kind === 'controlled' ? policyConfirmed.value : registerPolicyConfirmed[kind]) || xpplBusy.value) { return; }
    const signal = controller.signal;
    xpplBusy.value = true; errorKey.value = '';
    try {
        const result = await request(`${props.endpoint}/xppl/activate`, { method: 'POST', signal, body: { ...scopeData(), confirm: true, ...(kind === 'controlled' ? {} : { register_kind: kind }) } });
        if (!signal.aborted) { xpplOptions.value = result; policyConfirmed.value = false; registerPolicyConfirmed.incoming = false; registerPolicyConfirmed.outgoing = false; }
    } catch (error) { if (!signal.aborted) { fail(error); } }
    finally { if (!signal.aborted) { xpplBusy.value = false; } }
}
const xpplSeries = computed(() => xpplOptions.value.series.filter(item => xpplKind.value === 'xppl_controlled' ? item.register === 'controlled' && item.purpose === 'internal_register' : xpplKind.value === 'xppl_letter' && item.register === 'outgoing' && item.purpose === 'official_outgoing').map(item => ({ value: item.id, label: `${item.id} · ${item.site_name_en ?? item.site_id} · ${item.site_name_lo ?? ''} · ${t(`masterRegister-${item.register}`)} · ${t(`numberingPurpose-${item.purpose}`)}` })));
const selectedXpplSeries = computed(() => xpplOptions.value.series.find(item => item.id === Number(xpplForm.series_id)));
const selectedXpplDepartment = computed(() => xpplOptions.value.departments.find(item => item.id === Number(xpplForm.department_id)));
const xpplDepartments = computed(() => xpplOptions.value.departments.filter(item => xpplKind.value === 'xppl_controlled' || item.site_id === selectedXpplSeries.value?.site_id).map(item => ({ value: item.id, label: `${item.code} · ${language.value === 'lo' ? item.name_lo : item.name_en}` })));
const xpplTypes = computed(() => xpplOptions.value.types.filter(item => xpplKind.value === 'xppl_controlled' ? item.site_id === selectedXpplDepartment.value?.site_id : item.scope_id === selectedXpplSeries.value?.scope_id).map(item => ({ value: item.id, label: `${item.code} · ${language.value === 'lo' ? item.name_lo : item.name_en}` })));
async function loadXppl() {
    if (props.scope.scope_type !== 'company') { return; }
    const signal = controller.signal;
    try { const result = await request(url('/xppl/options'), { signal }); if (!signal.aborted) { xpplOptions.value = result; } }
    catch (error) { if (!signal.aborted) { fail(error); } }
}
async function reviewXppl(confirm = false) {
    if (xpplBusy.value || (confirm && (!cutoverConfirmed.value || !canManage.value))) { return; }
    const signal = controller.signal;
    xpplBusy.value = true; errorKey.value = ''; cutoverConfirmed.value = false;
    const body = { ...scopeData(), ...xpplForm, format: xpplKind.value };
    if (xpplKind.value === 'xppl_controlled') { delete body.series_id; }
    if (confirm) { Object.assign(body, { token: reconciliation.value.token, next_sequence: reconciliation.value.next_sequence, confirm: true }); }
    try {
        const result = await request(`${props.endpoint}/xppl/${confirm ? 'confirm' : 'review'}`, { method: 'POST', busy: confirm, signal, body });
        if (!signal.aborted) { reconciliation.value = result; if (confirm) { showSuccess(t('uiSaveSuccess'), t('numberingSaved')); await loadXppl(); } }
    } catch (error) { if (!signal.aborted) { reconciliation.value = null; fail(error); } }
    finally { if (!signal.aborted) { xpplBusy.value = false; } }
}
watch(xpplKind, () => { xpplForm.series_id = ''; xpplForm.department_id = ''; xpplForm.type_id = ''; reconciliation.value = null; cutoverConfirmed.value = false; });
watch(() => xpplForm.department_id, () => { if (xpplKind.value === 'xppl_controlled') { xpplForm.type_id = ''; } });
watch(() => xpplForm.series_id, () => {
    xpplForm.department_id = ''; xpplForm.type_id = '';
    if (xpplKind.value === 'xppl_letter') {
        const letters = xpplOptions.value.types.filter(item => item.scope_id === selectedXpplSeries.value?.scope_id && item.code === 'LTR');
        if (letters.length === 1) { xpplForm.type_id = letters[0].id; }
    }
});
watch(xpplForm, () => { reconciliation.value = null; cutoverConfirmed.value = false; });
let controller;
let previewController;
let historyController;
const canManage = computed(() => !revoked.value && props.scope.permissions.includes('numbering.manage'));
const options = computed(() => [{ value: '', label: t('uiChoose') }, ...registers.value.map(row => ({ value: row.id, label: t(`masterRegister-${row.register_key}`), disabled: !row.active }))]);
const purposes = computed(() => ['internal_register', 'official_outgoing'].map(value => ({ value, label: t(`numberingPurpose-${value}`), disabled: value === 'official_outgoing' && registers.value.find(row => row.id === Number(form.register_id))?.register_key !== 'outgoing' })));
const policies = computed(() => ['annual', 'continuous'].map(value => ({ value, label: t(`numberingPolicy-${value}`) })));
const columns = computed(() => [{ key: 'register_label', label: t('masterRegister') }, { key: 'purpose_label', label: t('numberingPurpose') }, { key: 'prefix', label: t('numberingPrefix') }, { key: 'format_definition', label: t('numberingFormatDefinition') }, { key: 'policy_label', label: t('numberingPolicy') }, { key: 'status', label: t('uiStatus') }, { key: 'actions', label: t('rbacActions') }]);
const displayRows = computed(() => rows.value.map(row => ({ ...row, register_label: t(`masterRegister-${row.register_key}`), purpose_label: t(`numberingPurpose-${row.purpose}`), prefix: row.configuration?.prefix ?? '—', format_definition: formatDefinition(row.configuration?.format), policy_label: t(`numberingPolicy-${row.reset_policy}`), status_key: !row.configuration?.active ? 'inactive' : row.effective ? 'active' : 'blocked', status: t(!row.configuration?.active ? 'rbacInactive' : row.effective ? 'rbacActive' : 'masterBlocked') })));
const historyColumns = computed(() => [{ key: 'version', label: t('numberingVersion') }, { key: 'prefix', label: t('numberingPrefix') }, { key: 'format_definition', label: t('numberingFormatDefinition') }, { key: 'padding', label: t('numberingPadding') }, { key: 'status', label: t('uiStatus') }, { key: 'effective_at', label: t('numberingEffective') }, { key: 'created_by', label: t('numberingActor') }, { key: 'change_reason', label: t('numberingReason') }]);
const historyRows = computed(() => history.value.map(row => ({ ...row, format_definition: formatDefinition(row.format), effective_at: formatDateTime(row.effective_at), status_key: row.active ? 'active' : 'inactive', status: t(row.active ? 'rbacActive' : 'rbacInactive') })));
function formatDefinition(format) { return format ? JSON.stringify(format, null, 2) : t('numberingLegacyFormat'); }
function formatData() { return form.custom_format ? { pattern: form.pattern, ...Object.fromEntries(['company', 'department', 'document_type'].filter(key => form[key] !== '').map(key => [key, form[key]])) } : null; }
function scopeData() { return { scope_type: props.scope.scope_type, company_id: props.scope.company_id, site_id: props.scope.site_id }; }
function url(path = '', requestedPage = 1) { return `${props.endpoint}${path}?${new URLSearchParams(Object.entries({ ...scopeData(), page: requestedPage, per_page: 10 }).filter(([, value]) => value !== null))}`; }
function fail(error) {
    if (error.name === 'AbortError') { return; }
    fields.value = error.fields ?? {};
    errorKey.value = Object.values(fields.value).flat().find(value => /^(rbac|numbering)/.test(value)) ?? error.message;
    if (!/^(rbac|numbering)/.test(errorKey.value)) { errorKey.value = 'rbacRequestFailed'; }
    if ([401, 403, 419].includes(error.status)) {
        revoked.value = true; controller?.abort(); previewController?.abort(); historyController?.abort();
        xpplOptions.value = { departments: [], types: [], series: [], history: [], mappings: [] }; reconciliation.value = null;
        policyConfirmed.value = false;
        registerPolicyConfirmed.incoming = false; registerPolicyConfirmed.outgoing = false;
        rows.value = []; registers.value = []; history.value = []; editor.value = null; historySeries.value = null; preview.value = ''; confirmationOpen.value = false;
        loading.value = false; previewLoading.value = false; historyLoading.value = false;
        emit('unauthorized', error);
    }
}
async function load() {
    const signal = controller.signal;
    loading.value = true;
    try {
        const result = await request(url('', page.value), { signal });
        if (!signal.aborted) { rows.value = result.data; total.value = result.meta.total; }
    } catch (error) { if (!signal.aborted) { fail(error); } }
    finally { if (!signal.aborted) { loading.value = false; } }
}
async function reset() {
    controller?.abort(); previewController?.abort(); historyController?.abort(); controller = new AbortController();
    const signal = controller.signal;
    revoked.value = false; rows.value = []; registers.value = []; editor.value = null; historySeries.value = null; history.value = []; preview.value = ''; fields.value = {}; errorKey.value = ''; confirmationOpen.value = false; page.value = 1; total.value = 0; previewLoading.value = false; historyLoading.value = false;
    xpplOptions.value = { departments: [], types: [], series: [], history: [], mappings: [] }; reconciliation.value = null; cutoverConfirmed.value = false; xpplBusy.value = false;
    policyConfirmed.value = false;
    registerPolicyConfirmed.incoming = false; registerPolicyConfirmed.outgoing = false;
    xpplKind.value = '';
    Object.assign(xpplForm, { series_id: '', department_id: '', type_id: '' });
    await load();
    if (!signal.aborted && !revoked.value) { await loadXppl(); }
    if (signal.aborted) { return; }
    try { const result = await request(url('/registers'), { signal }); if (!signal.aborted) { registers.value = result.data; } }
    catch (error) { if (!signal.aborted) { fail(error); } }
}
async function edit(row = null) {
    editor.value = { id: row?.id ?? null };
    fields.value = {}; errorKey.value = ''; preview.value = '';
    Object.assign(form, { register_id: row?.register_id ?? '', purpose: row?.purpose ?? 'internal_register', reset_policy: row?.reset_policy ?? 'annual', prefix: row?.configuration?.prefix ?? '', padding: String(row?.configuration?.padding ?? 6), active: row?.configuration?.active ?? true, change_reason: '', year: String(new Date().getFullYear()), custom_format: row?.configuration?.format != null, pattern: row?.configuration?.format?.pattern ?? '', company: row?.configuration?.format?.company ?? '', department: row?.configuration?.format?.department ?? '', document_type: row?.configuration?.format?.document_type ?? '' });
    previewController?.abort(); previewLoading.value = false;
    await nextTick();
    editorElement.value?.querySelector('h4')?.focus();
}
async function showPreview(savedSeries = false) {
    previewController?.abort(); previewController = new AbortController();
    const signal = previewController.signal;
    previewLoading.value = true; preview.value = ''; errorKey.value = ''; fields.value = {};
    try {
        const result = await request(`${props.endpoint}/preview`, { method: 'POST', busy: false, signal, body: savedSeries ? { ...scopeData(), series_id: editor.value.id } : { ...scopeData(), prefix: form.prefix, padding: Number(form.padding), year: Number(form.year), reset_policy: form.reset_policy, ...(form.custom_format ? { format: formatData() } : {}) } });
        if (!signal.aborted) { preview.value = result.number; }
    } catch (error) { if (!signal.aborted) { fail(error); } }
    finally { if (!signal.aborted) { previewLoading.value = false; } }
}
async function save() {
    if (!canManage.value || !editor.value || saving.value) { return; }
    const originalScope = props.scope.scope_key;
    const id = editor.value.id;
    const body = { ...scopeData(), prefix: form.prefix, padding: Number(form.padding), active: form.active, change_reason: form.change_reason, format: formatData() };
    if (!id) { Object.assign(body, { register_id: form.register_id, purpose: form.purpose, reset_policy: form.reset_policy }); }
    saving.value = true; errorKey.value = ''; fields.value = {};
    try {
        await request(`${props.endpoint}${id ? `/${id}` : ''}`, { method: id ? 'PATCH' : 'POST', body });
        if (originalScope !== props.scope.scope_key) { return; }
        editor.value = null; preview.value = ''; confirmationOpen.value = false; historySeries.value = null; history.value = [];
        showSuccess(t('uiSaveSuccess'), t('numberingSaved')); await load();
    } catch (error) {
        if (originalScope === props.scope.scope_key) { confirmationOpen.value = false; fail(error); await nextTick(); editorElement.value?.querySelector('[aria-invalid="true"]')?.focus(); }
    } finally { saving.value = false; }
}
async function loadHistory(row = historySeries.value) {
    historyController?.abort(); historyController = new AbortController();
    const signal = historyController.signal;
    if (!row) { return; }
    if (historySeries.value?.id !== row.id) { historyPage.value = 1; }
    historySeries.value = row; history.value = []; historyLoading.value = true;
    try {
        const result = await request(url(`/${row.id}/history`, historyPage.value), { signal });
        if (!signal.aborted) { history.value = result.data; historyTotal.value = result.total; }
    } catch (error) { if (!signal.aborted) { fail(error); } }
    finally { if (!signal.aborted) { historyLoading.value = false; } }
}
watch([() => form.prefix, () => form.padding, () => form.year, () => form.custom_format, () => form.pattern, () => form.company, () => form.department, () => form.document_type, () => form.reset_policy], () => { previewController?.abort(); previewLoading.value = false; preview.value = ''; });
watch(() => form.register_id, () => { if (!editor.value?.id && registers.value.find(row => row.id === Number(form.register_id))?.register_key !== 'outgoing') { form.purpose = 'internal_register'; } });
watch(() => props.scope.scope_key, reset, { immediate: true });
onBeforeUnmount(() => { controller?.abort(); previewController?.abort(); historyController?.abort(); });
</script>

<template>
    <section aria-labelledby="numbering-heading" class="flex flex-col gap-5">
        <h3 id="numbering-heading" class="text-lg font-semibold">{{ t('numberingTitle') }}</h3>
        <p class="text-sm leading-7 text-slate-600">{{ t('numberingAutomaticHelp') }}</p>
        <dl class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-2">
            <div class="flex flex-col gap-2"><dt class="text-sm font-semibold">{{ t('numberingFormat-xppl_controlled') }}</dt><dd class="wrap-anywhere font-mono text-sm">COMP-DEPT-TYPE-0001-R00</dd><dd class="text-sm text-slate-600">{{ t('numberingControlledHelp') }}</dd></div>
            <div class="flex flex-col gap-2"><dt class="text-sm font-semibold">{{ t('numberingFormat-xppl_letter') }}</dt><dd class="wrap-anywhere font-mono text-sm">COMP-DEPT-LTR-YY-0001</dd><dd class="text-sm text-slate-600">{{ t('numberingLetterHelp') }}</dd></div>
        </dl>
        <p class="text-xs leading-6 text-slate-500">{{ t('numberingCodesHelp') }}</p>
        <p v-if="errorKey && !editor" role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ t(errorKey) }}</p>
        <section v-if="!revoked && scope.scope_type === 'company' && xpplOptions.policy_available" class="flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-5" aria-labelledby="xppl-policy-title">
            <h4 id="xppl-policy-title" class="font-semibold">{{ t('numberingAdvanced') }}</h4>
            <p role="status">{{ t('numberingPolicyStatus') }}: {{ t(xpplOptions.policy ? 'rbacActive' : 'rbacInactive') }}</p>
            <p class="text-sm text-slate-600">{{ t('numberingPolicyHelp') }}</p>
            <template v-if="!xpplOptions.policy && canManage">
                <CheckboxInput v-model="policyConfirmed" :label="t('numberingPolicyConfirm')" :disabled="xpplBusy" />
                <UiButton :disabled="xpplBusy || !policyConfirmed" @click="activatePolicy()">{{ t('numberingPolicyActivate') }}</UiButton>
            </template>
        </section>
        <template v-if="!revoked && scope.scope_type === 'company' && xpplOptions.policy_available">
            <section v-for="kind in ['incoming', 'outgoing']" :key="kind" class="flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-5" :aria-labelledby="`xppl-policy-${kind}`">
                <h4 :id="`xppl-policy-${kind}`" class="font-semibold">{{ t(`numberingPolicy-${kind}`) }}</h4>
                <p role="status">{{ t(xpplOptions.correspondence_policies?.[kind] ? 'rbacActive' : 'rbacInactive') }}</p>
                <p class="font-mono text-sm">{{ kind === 'incoming' ? 'XPPL-IN-YYYY-NNNN' : 'XPPL-OUT-YYYY-NNNN · XPPL-DEPT-LTR-YY-NNNN' }}</p>
                <p class="text-sm text-slate-600">{{ t(`numberingPolicyHelp-${kind}`) }}</p>
                <template v-if="!xpplOptions.correspondence_policies?.[kind] && canManage">
                    <CheckboxInput v-model="registerPolicyConfirmed[kind]" :label="t(`numberingPolicyConfirm-${kind}`)" :disabled="xpplBusy" />
                    <UiButton :disabled="xpplBusy || !registerPolicyConfirmed[kind]" @click="activatePolicy(kind)">{{ t(`numberingPolicyActivate-${kind}`) }}</UiButton>
                </template>
            </section>
        </template>
        <details v-if="!revoked" :key="`advanced:${scope.scope_key}`" data-numbering-advanced class="rounded-xl border border-slate-200 bg-white p-5">
            <summary class="cursor-pointer font-semibold text-slate-900">{{ t('numberingAdvancedDetails') }}</summary>
            <p v-if="scope.scope_type !== 'company'" class="mt-4 text-sm leading-7 text-slate-600">{{ t('numberingXpplCompany') }}</p>
            <div v-else class="mt-4 flex flex-col gap-4">
                <p class="text-sm leading-7 text-slate-600">{{ t('numberingXpplHelp') }}</p>
                <SelectInput v-model="xpplKind" :label="t('numberingSetupType')" :options="xpplKinds" :disabled="xpplBusy" />
                <p v-if="xpplKind" class="text-sm text-slate-600">{{ t(xpplKind === 'xppl_letter' ? 'numberingLetterSetupHelp' : 'numberingControlledSetupHelp') }}</p>
                <div v-if="xpplKind" class="grid gap-4 sm:grid-cols-2">
                    <SelectInput v-if="xpplKind === 'xppl_letter'" v-model="xpplForm.series_id" :label="t('numberingXpplSeries')" :options="[{ value: '', label: t('uiChoose') }, ...xpplSeries]" :disabled="xpplBusy" />
                    <SelectInput v-if="xpplKind === 'xppl_controlled' || selectedXpplSeries" v-model="xpplForm.department_id" :label="t('numberingXpplDepartment')" :options="[{ value: '', label: t('uiChoose') }, ...xpplDepartments]" :disabled="xpplBusy" />
                    <SelectInput v-if="selectedXpplDepartment && xpplKind === 'xppl_controlled'" v-model="xpplForm.type_id" :label="t('numberingXpplType')" :options="[{ value: '', label: t('uiChoose') }, ...xpplTypes]" :disabled="xpplBusy" />
                </div>
                <p v-if="selectedXpplSeries && xpplKind === 'xppl_letter' && !xpplForm.type_id" role="status" class="text-sm text-slate-600">{{ t('numberingLetterTypeMissing') }}</p>
                <UiButton v-if="xpplKind" variant="secondary" :disabled="xpplBusy || (xpplKind === 'xppl_letter' && !xpplForm.series_id) || !xpplForm.department_id || !xpplForm.type_id" @click="reviewXppl()">{{ t('numberingXpplReview') }}</UiButton>
                <div v-if="reconciliation" role="status" class="flex flex-col gap-3 text-sm">
                    <p class="font-mono">{{ t(`numberingFormat-${reconciliation.format}`) }} · {{ reconciliation.preview }}{{ reconciliation.format === 'xppl_controlled' ? '-R00' : '' }}</p>
                    <p>{{ t('numberingXpplMaximum') }}: {{ reconciliation.historical_maximum }} · {{ t('numberingXpplNext') }}: {{ reconciliation.next_sequence }}</p>
                    <p>{{ t('numberingXpplCollisions') }}: {{ reconciliation.collision_count }} · {{ t('numberingXpplAmbiguous') }}: {{ reconciliation.ambiguous ? t('numberingXpplYes') : t('numberingXpplNo') }}</p>
                    <CheckboxInput v-if="canManage" v-model="cutoverConfirmed" :label="t('numberingXpplConfirmHelp')" :disabled="xpplBusy || reconciliation.ambiguous || reconciliation.collision_count > 0" />
                    <UiButton v-if="canManage" :disabled="xpplBusy || !cutoverConfirmed || reconciliation.ambiguous || reconciliation.collision_count > 0" @click="reviewXppl(true)">{{ t('numberingXpplConfirm') }}</UiButton>
                </div>
                <details class="rounded-lg border border-slate-200 p-4"><summary class="cursor-pointer text-sm font-semibold">{{ t('numberingHistory') }}</summary><div class="mt-3 flex flex-col gap-3">
                    <ul class="flex flex-col gap-2 text-sm"><li v-for="row in xpplOptions.mappings" :key="`${row.series_id}:${row.department_id}:${row.type_id}`">{{ row.prefix }} · {{ t('numberingXpplSeries') }} {{ row.series_id }} · {{ t('numberingXpplDepartment') }} {{ row.department_id }} · {{ t('numberingXpplType') }} {{ row.type_id }} · {{ t('numberingActor') }} {{ row.confirmed_by }} · {{ formatDateTime(row.created_at) }}</li></ul>
                    <ul class="flex flex-col gap-2 text-sm"><li v-for="row in xpplOptions.history" :key="`${row.prefix}:${row.period}`">{{ row.prefix }} · {{ t(`numberingFormat-${row.format}`) }} · {{ row.period }} · {{ t('numberingXpplBaseline') }} {{ row.baseline }} · {{ t('numberingActor') }} {{ row.confirmed_by }} · {{ formatDateTime(row.confirmed_at) }}</li></ul>
                </div></details>
            </div>
        </details>
        <details v-if="!revoked" :key="`legacy:${scope.scope_key}`" data-numbering-legacy class="rounded-xl border border-slate-200 bg-white p-5">
            <summary class="cursor-pointer font-semibold text-slate-900">{{ t('numberingLegacySettings') }}</summary>
            <div class="mt-4 flex flex-col gap-5">
                <p class="text-sm leading-7 text-slate-600">{{ t('numberingBoundary') }}</p>
                <div v-if="canManage"><UiButton :disabled="loading || saving" @click="edit()">{{ t('numberingCreate') }}</UiButton></div>
            <DataTable v-model:page="page" :rows="displayRows" :columns="columns" :caption="t('numberingTitle')" :loading="loading" server-side :total="total" :per-page="10" @change="load">
                <template #cell-format_definition="{ row }"><pre class="whitespace-pre-wrap wrap-anywhere font-mono text-xs">{{ row.format_definition }}</pre></template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status_key" :label="row.status" /></template>
                <template #cell-actions="{ row }"><div class="flex flex-wrap gap-2"><IconButton :action="canManage ? 'edit' : 'view'" :label="t(canManage ? 'rbacEdit' : 'rbacDetails')" :disabled="saving" @click="edit(row)" /><UiButton variant="secondary" :disabled="saving" @click="loadHistory(row)">{{ t('numberingHistory') }}</UiButton></div></template>
            </DataTable>
            <FormModal v-if="editor" :title="t(editor.id ? 'numberingEdit' : 'numberingCreate')" :subtitle="t('numberingIdentityHelp')" :busy="saving" @close="editor = null">
            <form ref="editorElement" novalidate class="flex flex-col gap-5" @submit.prevent="confirmationOpen = canManage">
                <p v-if="errorKey" role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ t(errorKey) }}</p>
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div class="sm:col-span-2"><SelectInput v-model="form.register_id" :label="t('masterRegister')" :options="options" :disabled="!!editor.id || !canManage || saving" :error-key="fields.register_id?.[0]" required /></div>
                    <SelectInput v-model="form.purpose" :label="t('numberingPurpose')" :options="purposes" :disabled="!!editor.id || !canManage || saving" :error-key="fields.purpose?.[0]" required />
                    <SelectInput v-model="form.reset_policy" :label="t('numberingPolicy')" :options="policies" :disabled="!!editor.id || !canManage || saving" :error-key="fields.reset_policy?.[0]" required />
                    <div class="grid grid-cols-1 gap-5 sm:col-span-2 sm:grid-cols-3">
                        <div class="sm:col-span-2"><TextInput v-model="form.prefix" :label="t('numberingPrefix')" :helper="t('numberingPrefixHelp')" :readonly="!canManage || saving" :error-key="fields.prefix?.[0]" maxlength="80" required /></div>
                        <TextInput v-model="form.padding" type="number" :label="t('numberingPadding')" :readonly="!canManage || saving" :error-key="fields.padding?.[0]" min="1" max="12" required />
                    </div>
                    <div class="sm:col-span-2"><TextInput v-model="form.change_reason" :label="t('numberingReason')" :readonly="!canManage || saving" :error-key="fields.change_reason?.[0]" maxlength="500" /></div>
                    <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 sm:col-span-2"><CheckboxInput v-model="form.active" :label="t('rbacActive')" :disabled="!canManage || saving" /></div>
                </div>
                <fieldset :disabled="!canManage || saving" class="flex flex-col gap-4 rounded-xl border border-slate-200 p-4">
                    <legend class="px-2 font-semibold">{{ t('numberingFormatDefinition') }}</legend>
                    <CheckboxInput v-model="form.custom_format" :label="t('numberingCustomFormat')" />
                    <p class="text-sm text-slate-600">{{ t(form.custom_format ? 'numberingFormatHelp' : 'numberingLegacyFormat') }}</p>
                    <template v-if="form.custom_format">
                        <TextInput v-model="form.pattern" :label="t('numberingFormatPattern')" :error-key="fields['format.pattern']?.[0] || fields.format?.[0]" maxlength="256" required />
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3"><TextInput v-for="code in ['company', 'department', 'document_type']" :key="code" v-model="form[code]" :label="t(`numberingComponent-${code}`)" :error-key="fields[`format.${code}`]?.[0]" maxlength="32" /></div>
                    </template>
                </fieldset>
                <div class="flex flex-col gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <div class="grid grid-cols-1 items-end gap-3 sm:grid-cols-[minmax(0,10rem)_auto_auto] sm:justify-start"><TextInput v-model="form.year" type="number" :label="t('numberingPreviewYear')" :error-key="fields.year?.[0]" min="2000" max="9999" /><UiButton variant="secondary" :loading="previewLoading" :disabled="saving" @click="showPreview()">{{ t('numberingPreview') }}</UiButton><UiButton v-if="editor.id" variant="secondary" :loading="previewLoading" :disabled="saving" @click="showPreview(true)">{{ t('numberingNextPreview') }}</UiButton></div>
                    <p v-if="preview" role="status" class="rounded-lg bg-white px-3 py-2 font-mono text-sm wrap-anywhere ring-1 ring-slate-200">{{ preview }}</p>
                </div>
                <div class="flex flex-wrap justify-end gap-3 border-t border-slate-200 pt-4"><UiButton variant="secondary" :disabled="saving" @click="editor = null">{{ t('uiCancel') }}</UiButton><UiButton v-if="canManage" type="submit" :disabled="saving">{{ t('numberingSave') }}</UiButton></div>
            </form>
            </FormModal>
            <section v-if="historySeries" class="flex flex-col gap-3" aria-labelledby="numbering-history"><h4 id="numbering-history" class="font-semibold">{{ t('numberingHistory') }} · {{ historySeries.configuration?.prefix }}</h4><DataTable v-model:page="historyPage" :rows="historyRows" :columns="historyColumns" :caption="t('numberingHistory')" :loading="historyLoading" server-side :total="historyTotal" :per-page="10" @change="loadHistory()"><template #cell-format_definition="{ row }"><pre class="whitespace-pre-wrap wrap-anywhere font-mono text-xs">{{ row.format_definition }}</pre></template><template #cell-status="{ row }"><StatusBadge :status="row.status_key" :label="row.status" /></template></DataTable></section>
            </div>
        </details>
        <ConfirmDialog v-model="confirmationOpen" :title="t('numberingSave')" :message="t('numberingSaveConfirm')" :loading="saving" @confirm="save" />
    </section>
</template>
