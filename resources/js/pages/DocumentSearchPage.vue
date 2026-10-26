<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useWorkspace } from '../utilities/workspace.js';
import { useLocalization } from '../localization/index.js';
import { createJsonClient } from '../utilities/jsonClient.js';
import { formatDate } from '../utilities/dates.js';
import TextInput from '../components/ui/TextInput.vue';
import SelectInput from '../components/ui/SelectInput.vue';
import CheckboxInput from '../components/ui/CheckboxInput.vue';
import UiButton from '../components/ui/UiButton.vue';
import DataTable from '../components/ui/DataTable.vue';

const props = defineProps({ endpoint: { type: String, required: true }, csrfToken: { type: String, required: true } });
const workspace = useWorkspace();
const { selected, units, unitKey } = workspace;
const { t, language } = useLocalization();
const request = createJsonClient(props.csrfToken);
const search = ref(''), register = ref(''), exact = ref(false), sort = ref('recent');
const page = ref(1), total = ref(0), rows = ref([]), loading = ref(false), error = ref('');
let controller;
const label = item => item?.[language.value === 'lo' ? 'name_lo' : 'name_en'] || item?.name_en || '';
const unitOptions = computed(() => units.value.map(item => ({ value: item.key, label: label(item.department) || t('siteCorrespondence') })));
const registerOptions = computed(() => [{ value: '', label: t('searchAllRegisters') }, ...['incoming', 'outgoing', 'controlled'].map(value => ({ value, label: t(value === 'controlled' ? 'controlled-documents' : value) }))]);
const sortOptions = computed(() => ['recent', 'oldest', 'number', 'date'].map(value => ({ value, label: t(`searchSort-${value}`) })));
const columns = computed(() => [{ key: 'register', label: t('searchRegister') }, { key: 'number', label: t('searchIdentity') }, { key: 'title', label: t('controlledTitleLabel') }, { key: 'type', label: t('controlledType') }, { key: 'document_date', label: t('searchDate') }, { key: 'status', label: t('uiStatus') }, { key: 'actions', label: t('rbacActions') }]);
async function load(reset = false) {
    controller?.abort(); controller = new AbortController(); const signal = controller.signal;
    rows.value = []; total.value = 0; error.value = '';
    if (reset) { page.value = 1; }
    if (!selected.value) { return; }
    loading.value = true;
    const scope = selected.value;
    const params = new URLSearchParams(Object.entries({ company_id: scope.company_id, site_id: scope.site_id, department_id: scope.department_id, search: search.value, register: register.value, exact: exact.value ? 1 : 0, sort: sort.value, page: page.value }).filter(([, value]) => value != null && value !== ''));
    try { const result = await request(`${props.endpoint}?${params}`, { signal }); if (!signal.aborted) { rows.value = result.data; total.value = result.meta.total; } }
    catch (failure) { if (!signal.aborted) { error.value = failure.status === 422 ? 'searchValidation' : 'rbacRequestFailed'; if ([401, 403, 419].includes(failure.status)) { workspace.revoke(); error.value = 'rbacForbidden'; } } }
    finally { if (!signal.aborted) { loading.value = false; } }
}
watch(() => selected.value?.key, () => load(true), { immediate: true });
onBeforeUnmount(() => controller?.abort());
</script>

<template>
    <section class="flex flex-col gap-5">
        <SelectInput v-if="units.length > 1" id="search-unit" v-model="unitKey" :label="t('department')" :options="unitOptions" class="max-w-sm" />
        <p v-if="!selected" role="status">{{ t('incomingNoContexts') }}</p>
        <template v-else>
            <form class="flex flex-wrap items-end gap-3" @submit.prevent="load(true)">
                <TextInput id="document-search" v-model="search" :label="t('searchQuery')" maxlength="200" class="min-w-56 grow" />
                <SelectInput id="search-register" v-model="register" :label="t('searchRegister')" :options="registerOptions" />
                <SelectInput id="search-sort" v-model="sort" :label="t('searchSort')" :options="sortOptions" />
                <CheckboxInput id="search-exact" v-model="exact" :label="t('searchExact')" />
                <UiButton type="submit" :disabled="loading">{{ t('document-search') }}</UiButton>
            </form>
            <p v-if="error" role="alert" class="rounded-xl bg-red-50 p-4 text-red-800">{{ t(error) }}</p>
            <DataTable v-model:page="page" :rows="rows" :columns="columns" row-key="key" :caption="t('document-search')" :loading="loading" :error="error" server-side :total="total" :per-page="10" @change="load()">
                <template #cell-register="{ row }">{{ t(row.register === 'controlled' ? 'controlled-documents' : row.register) }}</template>
                <template #cell-number="{ row }"><a :href="row.detail_url" class="font-semibold text-teal-800 hover:underline focus-visible:outline-2">{{ row.number || '—' }}</a><span v-if="row.register === 'controlled' && row.internal_number" class="block text-xs text-slate-600">{{ t('controlledNumber') }}: {{ row.internal_number }}</span><span v-if="row.official_number" class="block text-xs text-slate-600">{{ t('outgoingOfficialNumber') }}: {{ row.official_number }}</span><span v-if="row.external_reference" class="block text-xs text-slate-600">{{ t('incomingExternalReference') }}: {{ row.external_reference }}</span></template>
                <template #cell-title="{ row }">{{ language === 'lo' ? row.title_lo || row.title_en : row.title_en }}</template>
                <template #cell-type="{ row }">{{ label(row.category) }} / {{ label(row.type) }}</template>
                <template #cell-document_date="{ row }">{{ formatDate(row.document_date) }}</template>
                <template #cell-status="{ row }">{{ t(`${row.register === 'controlled' ? 'controlledStatus' : row.register === 'incoming' ? 'incomingStatus' : 'outgoingStatus'}-${row.status}`) }}</template>
                <template #cell-actions="{ row }"><a :href="row.detail_url" class="font-semibold text-teal-800 hover:underline focus-visible:outline-2">{{ t('rbacDetails') }}</a></template>
            </DataTable>
        </template>
    </section>
</template>
