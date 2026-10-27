<script setup>
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { useWorkspace } from '../utilities/workspace.js';
import { useLocalization } from '../localization/index.js';
import { createJsonClient } from '../utilities/jsonClient.js';
import { formatDate, formatDateTime } from '../utilities/dates.js';
import TextInput from '../components/ui/TextInput.vue';
import SelectInput from '../components/ui/SelectInput.vue';
import CheckboxInput from '../components/ui/CheckboxInput.vue';
import UiButton from '../components/ui/UiButton.vue';
import DataTable from '../components/ui/DataTable.vue';
import StatusBadge from '../components/ui/StatusBadge.vue';
import PdfPreviewDialog from '../components/ui/PdfPreviewDialog.vue';

const props = defineProps({ endpoint: { type: String, required: true }, csrfToken: { type: String, required: true } });
const { t, language } = useLocalization();
const workspace = useWorkspace();
const { selected, units, unitKey } = workspace;
const request = createJsonClient(props.csrfToken);
const filters = reactive({ view: 'documents', register: '', search: '', category_id: '', type_id: '', status: '', classification: '', date_from: '', date_to: '', owner: '', custodian: '', physical: '', custody_status: '', copy_kind: '', metric: '', event: '', outcome: '', operation: '', revision: '', revision_state: '', all_departments: 0, all_sites: 0 });
const initial = new URLSearchParams(window.location.search);
for (const key of Object.keys(filters)) { if (initial.has(key)) { filters[key] = initial.get(key); } }
if (filters.view === 'revisions') { filters.register = 'controlled'; }
const includeUnattributed = ref(false), capabilities = ref({}), eventChoices = ref([]);
const isAudit = computed(() => ['audit', 'security'].includes(filters.view));
const allUnits = ref(String(filters.all_departments) === '1'), allSites = ref(String(filters.all_sites) === '1');
const breakdown = ref('category_en'), trends = ref(null);
const page = ref(1), rows = ref([]), total = ref(0), summary = ref(null), types = ref([]), applied = ref(null), loading = ref(false), error = ref(''), preview = ref(false);
let controller;
const label = item => item?.[language.value === 'lo' ? 'name_lo' : 'name_en'] || item?.name_en || '';
const can = permission => selected.value?.permissions.includes(permission);
const viewOptions = computed(() => [...(can('reports.view') ? ['documents', 'revisions', 'physical'] : []), ...(can('documents.audit.view') ? ['audit'] : []), ...(can('security.audit.view') ? ['security'] : [])].map(value => ({ value, label: t(`reportView-${value}`) })));
const eventOptions = computed(() => [{ value: '', label: t('searchAny') }, ...eventChoices.value.map(item => ({ value: item.value, label: label(item) }))]);
const registerOptions = computed(() => [{ value: '', label: t('searchAllRegisters') }, ...['incoming', 'outgoing', 'controlled'].map(value => ({ value, label: t(value === 'controlled' ? 'controlled-documents' : value) }))]);
const options = (values, prefix) => [{ value: '', label: t('searchAny') }, ...values.map(value => ({ value, label: t(`${prefix}-${value}`) }))];
const typeOptions = computed(() => [{ value: '', label: t('searchAny') }, ...types.value.filter(type => (!filters.register || type.registers.includes(filters.register)) && (!filters.category_id || String(type.category_id) === String(filters.category_id))).map(type => ({ value: type.id, label: label(type) }))]);
const unitOptions = computed(() => units.value.map(item => ({ value: item.key, label: label(item.department) || t('siteCorrespondence') })));
const categoryOptions = computed(() => [{ value: '', label: t('searchAny') }, ...[...new Map(types.value.map(type => [type.category.id, type.category])).values()].map(item => ({ value: item.id, label: label(item) }))]);
const statusOptions = computed(() => options(filters.register === 'controlled' ? ['draft', 'current', 'superseded', 'obsolete', 'archived'] : filters.register === 'incoming' ? ['draft', 'received', 'assigned', 'in_progress', 'responded', 'closed'] : ['draft', 'registered', 'dispatched', 'delivered', 'closed'], filters.register === 'controlled' ? 'controlledStatus' : filters.register === 'incoming' ? 'incomingStatus' : 'outgoingStatus'));
const metricOptions = computed(() => options(filters.view === 'physical' ? ['checkout_overdue', 'inventory_discrepancy'] : filters.register === 'controlled' ? ['registered', 'review_soon', 'review_overdue'] : ['registered', 'open', 'closed', 'pending_response', 'in_progress', 'due_soon', 'overdue', 'dispatched', 'delivered', 'acknowledged'], 'metric'));
const columns = computed(() => isAudit.value ? [{ key: 'event', label: t('reportEvent') }, { key: 'number', label: t('searchIdentity') }, { key: 'actor', label: t('reportActor') }, { key: 'timestamp', label: t('reportTimestamp') }, { key: 'reason', label: t('controlledReason') }, { key: 'changes', label: t('reportChanges') }] : [{ key: 'number', label: t('searchIdentity') }, { key: 'title', label: t('controlledTitleLabel') }, ...(applied.value?.view === 'physical' ? [{ key: 'identifier', label: t('storageIdentifier') }, { key: 'custody', label: t('searchCustody') }, { key: 'location', label: t('storageLocation') }, { key: 'custodian', label: t('storageCustodian') }, { key: 'expected_return_date', label: t('storageExpectedReturn'), type: 'date' }] : applied.value?.view === 'revisions' ? [{ key: 'revision_identifier', label: t('controlledRevision') }, { key: 'revision_status', label: t('searchRevisionState') }, { key: 'revision_owner', label: t('controlled-owner_id') }, { key: 'revision_review_date', label: t('controlled-review_date'), type: 'date' }] : [{ key: 'register', label: t('register') }, { key: 'document_date', label: t('searchDate'), type: 'date' }, { key: 'status', label: t('uiStatus') }, { key: 'review_date', label: t('controlled-review_date'), type: 'date' }])]);
const activeFilters = computed(() => applied.value ? [applied.value.search, applied.value.register ? t(applied.value.register === 'controlled' ? 'controlled-documents' : applied.value.register) : '', applied.value.metric ? t(`metric-${applied.value.metric}`) : '', applied.value.status ? t(`${applied.value.register === 'controlled' ? 'controlledStatus' : applied.value.register === 'incoming' ? 'incomingStatus' : 'outgoingStatus'}-${applied.value.status}`) : '', label(types.value.find(type => String(type.id) === String(applied.value.type_id))), label(types.value.find(type => String(type.category_id) === String(applied.value.category_id))?.category), applied.value.classification ? t(`confidentiality-${applied.value.classification}`) : '', applied.value.owner, applied.value.custodian, applied.value.physical, applied.value.copy_kind ? t(`storageKind-${applied.value.copy_kind}`) : '', applied.value.custody_status ? t(`storageStatus-${applied.value.custody_status}`) : ''].filter(Boolean).join(' · ') : '');
const breakdownOptions = computed(() => ['category_en', 'type_en', 'department_en', 'company_en', 'site_en', 'owner_name', 'status'].map(value => ({ value, label: t(`reportGroup-${value}`) })));
function groupLabel(row) {
    if (breakdown.value === 'status') { return `${t(row.register === 'controlled' ? 'controlled-documents' : row.register)} · ${t(`${row.register === 'controlled' ? 'controlledStatus' : row.register === 'incoming' ? 'incomingStatus' : 'outgoingStatus'}-${row.status}`)}`; }
    return row[breakdown.value.replace(/_en$/, language.value === 'lo' ? '_lo' : '_en')] || row[breakdown.value] || '—';
}
function changeValue(field, value) {
    if (value == null) { return '—'; }
    if (typeof value === 'boolean') { return t(value ? 'reportYes' : 'reportNo'); }
    if (field.endsWith('_date')) { return formatDate(String(value).slice(0, 10)); }
    return String(value);
}
function params(values) { return new URLSearchParams(Object.entries(values).filter(([, value]) => value != null && value !== '')); }
function reportUrl(format) { return `${props.endpoint}/export?${params({ ...applied.value, language: language.value, format })}`; }
function fail(failure) {
    error.value = failure.status === 422 ? (Object.values(failure.fields ?? {}).flat().includes('reportTooLarge') ? 'reportTooLarge' : 'reportValidation') : failure.status === 403 ? 'rbacForbidden' : 'rbacRequestFailed';
    rows.value = []; total.value = 0; summary.value = null; trends.value = null; applied.value = null; preview.value = false;
    if ([401, 419].includes(failure.status)) { workspace.revoke(); }
}
async function load(reset = false, lookups = false) {
    controller?.abort(); controller = new AbortController(); const signal = controller.signal;
    preview.value = false; rows.value = []; total.value = 0; summary.value = null; trends.value = null; applied.value = null; error.value = ''; loading.value = false;
    if (reset) { page.value = 1; }
    if (!selected.value || !viewOptions.value.length) { return; }
    loading.value = true;
    const scope = selected.value;
    const query = { ...filters, company_id: scope.company_id, site_id: scope.site_id, department_id: scope.department_id, all_departments: allUnits.value || allSites.value ? 1 : 0, all_sites: allSites.value ? 1 : 0, include_unattributed: includeUnattributed.value ? 1 : 0, language: language.value, page: page.value };
    try {
        const result = await request(`${props.endpoint}?${params(query)}`, { signal });
        if (signal.aborted) { return; }
        rows.value = result.data; total.value = result.meta.total; summary.value = result.summary; capabilities.value = result.capabilities ?? {}; trends.value = result.trends; applied.value = { ...query };
        if (lookups) { const lookup = await request(`${props.endpoint}/lookups?${params(query)}`, { signal }); if (!signal.aborted) { types.value = lookup.data; eventChoices.value = lookup.events ?? []; } }
    } catch (failure) { if (!signal.aborted) { fail(failure); } }
    finally { if (!signal.aborted) { loading.value = false; } }
}
watch(() => selected.value?.key, async () => { types.value = []; filters.type_id = ''; filters.category_id = ''; includeUnattributed.value = false; if (viewOptions.value.length && !viewOptions.value.some(item => item.value === filters.view)) { filters.view = viewOptions.value[0].value; } await nextTick(); load(true, true); }, { immediate: true });
watch(() => filters.register, () => { filters.status = ''; filters.type_id = ''; filters.category_id = ''; filters.metric = ''; filters.owner = ''; filters.revision = ''; filters.revision_state = ''; });
watch(() => filters.view, async view => { filters.metric = ''; filters.status = ''; filters.type_id = ''; filters.category_id = ''; filters.revision = ''; filters.revision_state = ''; filters.physical = ''; filters.custodian = ''; filters.custody_status = ''; filters.copy_kind = ''; filters.event = ''; filters.outcome = ''; filters.operation = ''; includeUnattributed.value = false; if (view === 'revisions') { filters.register = 'controlled'; } await nextTick(); load(true, true); });
watch([allUnits, allSites, includeUnattributed], () => load(true, true));
onBeforeUnmount(() => controller?.abort());
</script>

<template>
    <section class="flex flex-col gap-4">
        <p v-if="!selected" role="status">{{ t('incomingNoContexts') }}</p>
        <p v-else-if="!viewOptions.length" role="alert" class="rounded-xl bg-slate-100 p-4">{{ t('reportNoPermission') }}</p>
        <template v-else>
            <div class="flex flex-wrap items-end gap-3"><SelectInput id="report-view" v-model="filters.view" :label="t('reportView')" :options="viewOptions" /><SelectInput v-if="units.length > 1 && !allUnits && !allSites" id="report-unit" v-model="unitKey" :label="t('department')" :options="unitOptions" /><CheckboxInput id="report-all-units" v-model="allUnits" :label="t('reportAllUnits')" /><CheckboxInput id="report-all-sites" v-model="allSites" :label="t('reportAllSites')" /></div>
            <form class="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="load(true)">
                <TextInput id="report-search" v-model="filters.search" :label="t('searchQuery')" maxlength="200" />
                <SelectInput id="report-register" v-model="filters.register" :label="t('register')" :options="registerOptions" :disabled="filters.view === 'revisions'" />
                <TextInput id="report-from" v-model="filters.date_from" type="date" :label="t('searchFrom')" /><TextInput id="report-to" v-model="filters.date_to" type="date" :label="t('searchTo')" />
                <SelectInput v-if="!isAudit" id="report-metric" v-model="filters.metric" :label="t('reportMeasure')" :options="metricOptions" />
                <SelectInput v-if="filters.view === 'security'" id="report-outcome" v-model="filters.outcome" :label="t('reportOutcome')" :options="options(['allowed', 'denied', 'activity'], 'reportOutcome')" />
                <UiButton type="submit" :disabled="loading" class="self-end">{{ t('incomingApplyFilters') }}</UiButton>
                <details class="sm:col-span-2 lg:col-span-4"><summary class="cursor-pointer text-sm font-semibold text-teal-800">{{ t('searchFilters') }}</summary><div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <SelectInput id="report-category" v-model="filters.category_id" :label="t('searchCategory')" :options="categoryOptions" /><SelectInput id="report-type" v-model="filters.type_id" :label="t('controlledType')" :options="typeOptions" />
                    <SelectInput v-if="filters.register" id="report-status" v-model="filters.status" :label="t('uiStatus')" :options="statusOptions" />
                    <SelectInput v-if="isAudit" id="report-event" v-model="filters.event" :label="t('reportEvent')" :options="eventOptions" />
                    <SelectInput v-if="filters.view === 'security'" id="report-operation" v-model="filters.operation" :label="t('reportOperation')" :options="options(['metadata', 'preview', 'download'], 'documentOperation')" />
                    <CheckboxInput v-if="filters.view === 'security' && capabilities.include_unattributed" id="report-unattributed" v-model="includeUnattributed" :label="t('reportUnattributed')" />
                    <SelectInput id="report-classification" v-model="filters.classification" :label="t('outgoingClassification')" :options="options(['normal', 'confidential', 'highly_confidential'], 'confidentiality')" />
                    <TextInput v-if="!filters.register || filters.register === 'controlled'" id="report-owner" v-model="filters.owner" :label="t('searchOwner')" maxlength="200" />
                    <template v-if="filters.view === 'physical'"><TextInput id="report-location" v-model="filters.physical" :label="t('searchPhysical')" maxlength="200" /><TextInput id="report-custodian" v-model="filters.custodian" :label="t('storageCustodian')" maxlength="200" /><SelectInput id="report-custody" v-model="filters.custody_status" :label="t('searchCustody')" :options="options(['stored', 'checked_out', 'missing'], 'storageStatus')" /><SelectInput id="report-kind" v-model="filters.copy_kind" :label="t('searchCopyKind')" :options="options(['original', 'copy'], 'storageKind')" /></template>
                    <template v-if="filters.view === 'revisions'"><TextInput id="report-revision" v-model="filters.revision" :label="t('controlledRevision')" inputmode="numeric" /><SelectInput id="report-revision-status" v-model="filters.revision_state" :label="t('searchRevisionState')" :options="options(['current', 'draft', 'superseded'], 'controlledStatus')" /></template>
                </div></details>
            </form>
            <p v-if="error" role="alert" class="rounded-xl bg-red-50 p-4 text-red-800">{{ t(error) }}</p>
            <div v-if="applied" class="flex flex-wrap items-center justify-between gap-3"><div class="text-sm text-slate-600"><p>{{ t('reportPeriod') }}: {{ formatDate(applied.date_from, t('searchAny')) }} – {{ formatDate(applied.date_to, t('searchAny')) }}</p><p v-if="activeFilters">{{ activeFilters }}</p><p>{{ t(isAudit ? 'reportAuditPeriodHelp' : 'searchDateHelp') }}</p></div><div class="flex flex-wrap gap-2"><a :href="reportUrl('xlsx')" class="inline-flex min-h-10 items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">{{ t('reportDownloadXlsx') }}</a><UiButton variant="secondary" @click="preview = true">{{ t('reportPreviewPdf') }}</UiButton></div></div>
            <dl v-if="summary" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"><div v-for="key in isAudit ? ['events'] : filters.view === 'physical' ? ['physical_copies', 'stored', 'checked_out', 'checkout_overdue'] : filters.view === 'revisions' ? ['controlled', 'revisions', 'draft_revisions', 'superseded_revisions'] : ['total', 'open_incoming', 'pending_response', 'closed']" :key="key" class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 bg-white p-3"><dt class="text-sm text-slate-600">{{ t(`metric-${key}`) }}</dt><dd class="font-semibold tabular-nums">{{ summary[key] }}</dd></div></dl>
            <details v-if="trends" class="rounded-xl border border-slate-200 bg-white p-4"><summary class="cursor-pointer font-semibold">{{ t('reportTrends') }}</summary><SelectInput id="report-breakdown" v-model="breakdown" :label="t('reportGroup')" :options="breakdownOptions" class="my-3 max-w-sm" /><table class="w-full text-left text-sm"><caption class="sr-only">{{ t('reportTrends') }}</caption><thead><tr><th class="py-2">{{ t(`reportGroup-${breakdown}`) }}</th><th class="text-right">{{ t('reportCount') }}</th></tr></thead><tbody><tr v-for="(row, index) in trends[breakdown]" :key="index" class="border-t border-slate-100"><td class="py-2">{{ groupLabel(row) }}</td><td class="text-right tabular-nums">{{ row.total }}</td></tr></tbody></table></details>
            <details class="rounded-xl border border-slate-200 bg-white p-4"><summary class="cursor-pointer font-semibold">{{ t('reportDefinitions') }}</summary><p class="mt-3 text-sm leading-7 text-slate-600">{{ t('reportDefinitionsText') }}</p></details>
            <DataTable v-model:page="page" :columns="columns" :rows="rows" row-key="key" :caption="t(`reportView-${applied?.view || filters.view}`)" :loading="loading" :error="error" server-side :total="total" :per-page="10" @change="load()">
                <template #cell-number="{ row }"><a v-if="row.href" :href="row.href" class="font-semibold text-teal-800 hover:underline">{{ row.number || '—' }}</a><span v-else>{{ row.number || (language === 'lo' ? row.title_lo : row.title_en) || '—' }}</span><span v-if="row.official_number" class="block text-xs text-slate-600">{{ t('outgoingOfficialNumber') }}: {{ row.official_number }}</span></template>
                <template #cell-event="{ row }">{{ language === 'lo' ? row.event_lo || row.event : row.event_en || row.event }}<span class="block text-xs text-slate-500">{{ language === 'lo' ? row.source_lo : row.source_en }}</span><span v-if="row.issued_number" class="block font-mono text-xs">{{ row.issued_number }}</span></template>
                <template #cell-timestamp="{ row }">{{ formatDateTime(row.timestamp) }}</template>
                <template #cell-changes="{ row }"><details v-if="row.changes?.length"><summary class="cursor-pointer text-teal-800">{{ t('reportChanges') }}</summary><dl class="mt-2 flex flex-col gap-2"><div v-for="change in row.changes" :key="change.field"><dt class="text-xs font-semibold">{{ language === 'lo' ? change.label_lo : change.label_en }}</dt><dd class="whitespace-pre-wrap text-xs">{{ changeValue(change.field, change.before) }} → {{ changeValue(change.field, change.after) }}</dd></div></dl></details><span v-else>—</span></template>
                <template #cell-title="{ row }">{{ language === 'lo' ? row.title_lo || row.title_en : row.title_en }}</template>
                <template #cell-register="{ row }">{{ t(row.register === 'controlled' ? 'controlled-documents' : row.register) }}</template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status" :label="t(`${row.register === 'controlled' ? 'controlledStatus' : row.register === 'incoming' ? 'incomingStatus' : 'outgoingStatus'}-${row.status}`)" /></template>
                <template #cell-revision_status="{ row }"><StatusBadge :status="row.revision_status" :label="t(`controlledStatus-${row.revision_status}`)" /></template>
                <template #cell-custody="{ row }">{{ t(`storageKind-${row.kind}`) }} · {{ t(`storageStatus-${row.custody_status}`) }}</template>
                <template #cell-location="{ row }">{{ [row.room, row.cabinet, row.shelf, row.box].filter(Boolean).join(' → ') }}</template>
            </DataTable>
            <PdfPreviewDialog v-if="preview && applied" v-model="preview" :url="reportUrl('pdf')" filename="dcms-report.pdf" :title="t(`reportView-${applied.view}`)" :csrf-token="csrfToken" @failure="fail" />
        </template>
    </section>
</template>
