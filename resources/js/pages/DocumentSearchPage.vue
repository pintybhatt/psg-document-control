<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { useWorkspace } from '../utilities/workspace.js';
import { useLocalization } from '../localization/index.js';
import { createJsonClient } from '../utilities/jsonClient.js';
import { formatDate } from '../utilities/dates.js';
import TextInput from '../components/ui/TextInput.vue';
import SelectInput from '../components/ui/SelectInput.vue';
import CheckboxInput from '../components/ui/CheckboxInput.vue';
import UiButton from '../components/ui/UiButton.vue';
import DataTable from '../components/ui/DataTable.vue';
import StatusBadge from '../components/ui/StatusBadge.vue';

const props = defineProps({ endpoint: { type: String, required: true }, csrfToken: { type: String, required: true } });
const workspace = useWorkspace();
const { selected, units, unitKey } = workspace;
const { t, language } = useLocalization();
const request = createJsonClient(props.csrfToken);
const search = ref(''), register = ref(''), exact = ref(false), sort = ref('recent');
const page = ref(1), total = ref(0), rows = ref([]), loading = ref(false), error = ref('');
let controller;
const types = ref([]);
const filters = reactive({ category_id: '', type_id: '', status: '', classification: '', date_from: '', date_to: '', revision: '', revision_state: '', owner: '', physical: '', custodian: '', copy_kind: '', custody_status: '' });
const applicableTypes = computed(() => types.value.filter(item => (!register.value || item.registers.includes(register.value)) && (!filters.category_id || String(item.category_id) === String(filters.category_id))));
const typeOptions = computed(() => [{ value: '', label: t('searchAny') }, ...applicableTypes.value.map(item => ({ value: item.id, label: label(item) }))]);
const categories = computed(() => [...new Map(types.value.filter(item => !register.value || item.registers.includes(register.value)).map(item => [item.category.id, item.category])).values()]);
const categoryOptions = computed(() => [{ value: '', label: t('searchAny') }, ...categories.value.map(item => ({ value: item.id, label: label(item) }))]);
const statusOptions = computed(() => [{ value: '', label: t('searchAny') }, ...(register.value === 'controlled' ? ['draft', 'current', 'superseded', 'obsolete', 'archived'] : register.value === 'incoming' ? ['draft', 'received', 'assigned', 'in_progress', 'responded', 'closed'] : ['draft', 'registered', 'dispatched', 'delivered', 'closed']).map(value => ({ value, label: t(`${register.value === 'controlled' ? 'controlledStatus' : register.value === 'incoming' ? 'incomingStatus' : 'outgoingStatus'}-${value}`) }))]);
const classificationOptions = computed(() => [{ value: '', label: t('searchAny') }, ...['normal', 'confidential', 'highly_confidential'].map(value => ({ value, label: t(`confidentiality-${value}`) }))]);
const kindOptions = computed(() => [{ value: '', label: t('searchAny') }, ...['original', 'copy'].map(value => ({ value, label: t(`storageKind-${value}`) }))]);
const custodyOptions = computed(() => [{ value: '', label: t('searchAny') }, ...['stored', 'checked_out', 'missing'].map(value => ({ value, label: t(`storageStatus-${value}`) }))]);
const revisionOptions = computed(() => [{ value: '', label: t('searchAny') }, ...['current', 'draft', 'superseded'].map(value => ({ value, label: t(`controlledStatus-${value}`) }))]);
const label = item => item?.[language.value === 'lo' ? 'name_lo' : 'name_en'] || item?.name_en || '';
const unitOptions = computed(() => units.value.map(item => ({ value: item.key, label: label(item.department) || t('siteCorrespondence') })));
const registerOptions = computed(() => [{ value: '', label: t('searchAllRegisters') }, ...['incoming', 'outgoing', 'controlled'].map(value => ({ value, label: t(value === 'controlled' ? 'controlled-documents' : value) }))]);
const sortOptions = computed(() => ['recent', 'oldest', 'number', 'date'].map(value => ({ value, label: t(`searchSort-${value}`) })));
const columns = computed(() => [{ key: 'register', label: t('searchRegister') }, { key: 'number', label: t('searchIdentity') }, { key: 'title', label: t('controlledTitleLabel') }, { key: 'type', label: t('controlledType') }, { key: 'document_date', label: t('searchDate') }, { key: 'status', label: t('uiStatus') }, { key: 'physical', label: t('searchPhysical') }, { key: 'actions', label: t('rbacActions') }]);
async function load(reset = false) {
    controller?.abort(); controller = new AbortController(); const signal = controller.signal;
    rows.value = []; total.value = 0; error.value = ''; loading.value = false;
    if (reset) { page.value = 1; }
    if (!selected.value) { return; }
    loading.value = true;
    const scope = selected.value;
    const params = new URLSearchParams(Object.entries({ company_id: scope.company_id, site_id: scope.site_id, department_id: scope.department_id, search: search.value, register: register.value, exact: exact.value ? 1 : 0, sort: sort.value, page: page.value, ...filters }).filter(([, value]) => value != null && value !== ''));
    try { const result = await request(`${props.endpoint}?${params}`, { signal }); if (!signal.aborted) { rows.value = result.data; total.value = result.meta.total; } }
    catch (failure) { if (!signal.aborted) { error.value = failure.status === 422 ? 'searchValidation' : 'rbacRequestFailed'; if ([401, 403, 419].includes(failure.status)) { workspace.revoke(); error.value = 'rbacForbidden'; } } }
    finally { if (!signal.aborted) { loading.value = false; } }
}
watch(register, () => { filters.status = ''; filters.type_id = ''; filters.category_id = ''; filters.revision = ''; filters.revision_state = ''; filters.owner = ''; load(true); });
watch(() => filters.category_id, () => { filters.type_id = ''; });
watch(() => selected.value?.key, async (key, previous, onCleanup) => {
    const lookupController = new AbortController(); const signal = lookupController.signal;
    onCleanup(() => lookupController.abort());
    types.value = []; Object.keys(filters).forEach(key => { filters[key] = ''; });
    await load(true);
    if (!selected.value || signal.aborted) { return; }
    const scope = selected.value;
    try { const result = await request(`${props.endpoint}/lookups?${new URLSearchParams(Object.entries({ company_id: scope.company_id, site_id: scope.site_id, department_id: scope.department_id }).filter(([, value]) => value != null))}`, { signal }); if (!signal.aborted) { types.value = result.data; } } catch (failure) { if (!signal.aborted) { error.value = 'rbacRequestFailed'; if ([401, 403, 419].includes(failure.status)) { rows.value = []; total.value = 0; types.value = []; workspace.revoke(); } } }
}, { immediate: true });
onBeforeUnmount(() => controller?.abort());
</script>

<template>
    <section class="flex flex-col gap-5">
        <p v-if="error || workspace.error.value" role="alert" class="rounded-xl bg-red-50 p-4 text-red-800">{{ t(error || workspace.error.value) }}</p>
        <p v-if="workspace.loading.value" role="status">{{ t('uiLoading') }}</p>
        <p v-else-if="!selected" role="status">{{ t('incomingNoContexts') }}</p>
        <template v-else>
            <form class="grid grid-cols-1 items-end gap-3 sm:grid-cols-2" :class="units.length > 1 ? 'xl:grid-cols-[minmax(0,1.5fr)_minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_auto_auto]' : 'xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_auto_auto]'" @submit.prevent="load(true)">
                <SelectInput v-if="units.length > 1" id="search-unit" v-model="unitKey" :label="t('department')" :options="unitOptions" />
                <TextInput id="document-search" v-model="search" :label="t('searchQuery')" maxlength="200" />
                <SelectInput id="search-register" v-model="register" :label="t('searchRegister')" :options="registerOptions" />
                <SelectInput id="search-sort" v-model="sort" :label="t('searchSort')" :options="sortOptions" />
                <div class="flex min-h-10 items-center"><CheckboxInput id="search-exact" v-model="exact" :label="t('searchExact')" /></div>
                <UiButton type="submit" :disabled="loading">{{ t('document-search') }}</UiButton>
            </form>
            <details class="rounded-xl border border-slate-200 bg-white p-4">
                <summary class="cursor-pointer font-semibold">{{ t('searchFilters') }}</summary>
                <form class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="load(true)">
                    <SelectInput id="search-category" v-model="filters.category_id" :label="t('searchCategory')" :options="categoryOptions" />
                    <SelectInput id="search-type" v-model="filters.type_id" :label="t('controlledType')" :options="typeOptions" />
                    <SelectInput v-if="register" id="search-status" v-model="filters.status" :label="t('uiStatus')" :options="statusOptions" />
                    <SelectInput id="search-classification" v-model="filters.classification" :label="t('outgoingClassification')" :options="classificationOptions" />
                    <TextInput id="search-from" v-model="filters.date_from" type="date" :label="t('searchFrom')" />
                    <TextInput id="search-to" v-model="filters.date_to" type="date" :label="t('searchTo')" />
                    <template v-if="register === 'controlled'">
                        <TextInput id="search-revision" v-model="filters.revision" placeholder="R00" :label="t('controlledRevision')" />
                        <SelectInput id="search-revision-state" v-model="filters.revision_state" :label="t('searchRevisionState')" :options="revisionOptions" />
                        <TextInput id="search-owner" v-model="filters.owner" :label="t('searchOwner')" maxlength="200" />
                    </template>
                    <TextInput id="search-physical" v-model="filters.physical" :label="t('searchPhysical')" maxlength="200" />
                    <TextInput id="search-custodian" v-model="filters.custodian" :label="t('searchCustodian')" maxlength="200" />
                    <SelectInput id="search-kind" v-model="filters.copy_kind" :label="t('searchCopyKind')" :options="kindOptions" />
                    <SelectInput id="search-custody" v-model="filters.custody_status" :label="t('searchCustody')" :options="custodyOptions" />
                    <p class="self-center text-xs text-slate-600">{{ t('searchDateHelp') }}</p>
                    <p v-if="!register || register === 'controlled'" class="self-center text-xs text-slate-600">{{ t('searchHistoryHelp') }}</p>
                    <UiButton type="submit" :disabled="loading" class="self-end">{{ t('incomingApplyFilters') }}</UiButton>
                </form>
            </details>
            <DataTable v-model:page="page" :rows="rows" :columns="columns" row-key="key" :caption="t('document-search')" :loading="loading" :error="error" server-side :total="total" :per-page="10" @change="load()">
                <template #cell-register="{ row }">{{ t(row.register === 'controlled' ? 'controlled-documents' : row.register) }}</template>
                <template #cell-number="{ row }"><a :href="row.detail_url" class="font-semibold text-teal-800 hover:underline focus-visible:outline-2">{{ row.number || '—' }}</a><span v-if="row.register === 'controlled' && row.internal_number" class="block text-xs text-slate-600">{{ t('controlledNumber') }}: {{ row.internal_number }}</span><span v-if="row.official_number" class="block text-xs text-slate-600">{{ t('outgoingOfficialNumber') }}: {{ row.official_number }}</span><span v-if="row.external_reference" class="block text-xs text-slate-600">{{ t('incoming-external_reference') }}: {{ row.external_reference }}</span></template>
                <template #cell-title="{ row }">{{ language === 'lo' ? row.title_lo || row.title_en : row.title_en }}<span v-if="row.register === 'controlled'" class="block text-xs text-slate-600">{{ t('controlledAuthoritative') }}: {{ row.authoritative_revision == null ? t('controlledNoAuthority') : `R${String(row.authoritative_revision).padStart(2, '0')}` }}<span v-if="row.draft_revision != null" class="block">{{ t('controlledStatus-draft') }}: {{ `R${String(row.draft_revision).padStart(2, '0')}` }}</span></span></template>
                <template #cell-type="{ row }">{{ label(row.category) }} / {{ label(row.type) }}</template>
                <template #cell-document_date="{ row }">{{ formatDate(row.document_date) }}</template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status" :label="t(`${row.register === 'controlled' ? 'controlledStatus' : row.register === 'incoming' ? 'incomingStatus' : 'outgoingStatus'}-${row.status}`)" /></template>
                <template #cell-physical="{ row }"><ul v-if="row.physical_copies?.length" class="flex flex-col gap-2 text-xs"><li v-for="copy in row.physical_copies" :key="copy.identifier"><span class="font-semibold">{{ copy.identifier }}</span> · {{ t(`storageKind-${copy.kind}`) }}<span v-if="copy.revision" class="block">{{ t('controlledRevision') }} {{ copy.revision }} · {{ t(`controlledStatus-${copy.revision_status}`) }}</span><span class="block">{{ copy.location }}</span><span class="block">{{ t(`storageStatus-${copy.status}`) }}<span v-if="copy.custodian"> · {{ copy.custodian }}</span></span></li></ul><span v-else>—</span><a v-if="row.physical_copies?.length" :href="row.detail_url" class="block text-xs text-teal-800 hover:underline">{{ t('searchPhysicalDetails') }}</a></template>
                <template #cell-actions="{ row }"><a :href="row.detail_url" class="font-semibold text-teal-800 hover:underline focus-visible:outline-2">{{ t('rbacDetails') }}</a></template>
            </DataTable>
        </template>
    </section>
</template>
