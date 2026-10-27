<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import AppIcon from '../components/AppIcon.vue';
import DataTable from '../components/ui/DataTable.vue';
import StatusBadge from '../components/ui/StatusBadge.vue';
import { useLocalization } from '../localization/index.js';
import { useWorkspace } from '../utilities/workspace.js';
import { createJsonClient } from '../utilities/jsonClient.js';

const props = defineProps({ navigation: { type: Array, required: true }, context: { type: Object, required: true }, endpoint: { type: String, default: '' }, csrfToken: { type: String, default: '' } });
const { t, language } = useLocalization();
const workspace = useWorkspace();
const data = ref(null);
const loading = ref(false);
const error = ref('');
let controller;
const current = computed(() => workspace?.sites.value.find(item => `${item.company_id}:${item.site_id}` === workspace.siteKey.value));
const name = item => item?.[language.value === 'lo' ? 'name_lo' : 'name_en'] ?? '';
const columns = computed(() => [{ key: 'kind', label: t('register') }, { key: 'subject', label: t('documentSubject') }, { key: 'date', label: t('documentDate'), type: 'date' }, { key: 'status', label: t('uiStatus') }]);
const operational = ['open_incoming', 'open_outgoing', 'pending_response', 'closed', 'due_soon', 'review_soon', 'review_overdue', 'checkout_overdue'];
const dueColumns = computed(() => [{ key: 'subject', label: t('documentSubject') }, { key: 'due_date', label: t('incomingDueDate'), type: 'date' }, { key: 'status', label: t('uiStatus') }]);
async function load() {
    controller?.abort(); controller = new AbortController(); const signal = controller.signal;
    data.value = null; error.value = ''; loading.value = false;
    if (!current.value || !props.endpoint) { return; }
    loading.value = true;
    try {
        const result = await createJsonClient(props.csrfToken)(`${props.endpoint}?${new URLSearchParams({ company_id: current.value.company_id, site_id: current.value.site_id })}`, { signal });
        if (!signal.aborted) { data.value = result; }
    } catch (failure) { if (!signal.aborted) { error.value = failure.message; } }
    finally { if (!signal.aborted) { loading.value = false; } }
}
watch(() => workspace?.siteKey.value, load, { immediate: true });
onBeforeUnmount(() => controller?.abort());
</script>

<template>
    <section class="flex flex-wrap items-center justify-between gap-4 rounded-xl bg-slate-900 p-5 text-white">
        <div><h2 class="text-lg font-semibold">{{ t('documentOverview') }}</h2><p class="mt-1 text-sm text-slate-300">{{ current ? `${name(current.company)} / ${name(current.site)}` : t('noSite') }}</p></div>
        <div class="flex flex-wrap gap-2"><a v-for="action in data?.quick_actions ?? []" :key="action.kind" :href="action.href" class="inline-flex items-center gap-2 rounded-lg bg-teal-300 px-4 py-2 text-sm font-semibold text-slate-950"><AppIcon :name="action.kind" class="size-4" />{{ t(`${action.kind}Create`) }}</a></div>
    </section>
    <p v-if="error" role="alert" class="rounded-lg bg-red-50 p-4 text-red-800">{{ t(error) }}</p>
    <p v-else-if="!current && !workspace?.loading.value" class="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-600">{{ t('workspaceNoContexts') }}</p>
    <template v-if="current">
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" :aria-busy="loading">
            <section v-for="key in ['incoming', 'outgoing', ...(data?.totals.controlled !== undefined ? ['controlled'] : ['due_soon']), 'overdue']" :key="key" class="rounded-xl border border-slate-200 bg-white p-5">
                <h3 class="text-sm font-medium text-slate-600">{{ t(`overview-${key}`) }}</h3><p class="mt-2 text-3xl font-semibold tabular-nums" :class="key === 'overdue' && data?.totals.overdue ? 'text-red-700' : 'text-slate-900'">{{ data ? data.totals[key] : '—' }}</p>
            </section>
        </div>
        <section v-if="data?.totals.controlled !== undefined" class="rounded-xl border border-slate-200 bg-white p-5">
            <h2 class="mb-3 text-lg font-semibold">{{ t('reportOperational') }}</h2>
            <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2 lg:grid-cols-4"><div v-for="key in operational" :key="key" class="flex items-center justify-between gap-3 border-b border-slate-100 pb-2"><dt class="text-sm text-slate-600">{{ t(`metric-${key}`) }}</dt><dd class="font-semibold tabular-nums" :class="key.includes('overdue') && data.totals[key] ? 'text-red-700' : 'text-slate-900'">{{ data.totals[key] }}</dd></div></dl>
        </section>
        <section v-if="data?.reviews?.length" class="rounded-xl border border-slate-200 bg-white p-5"><h2 class="mb-3 text-lg font-semibold">{{ t('reportReviewDates') }}</h2><ul class="grid gap-3 sm:grid-cols-2"><li v-for="row in data.reviews" :key="row.href"><a :href="row.href" class="font-semibold text-teal-800 hover:underline">{{ row.number }} · {{ language === 'lo' ? row.title_lo || row.title_en : row.title_en }}</a><p class="text-sm text-slate-600">{{ t('controlled-review_date') }}: {{ row.review_date?.split('-').reverse().join('/') }}</p></li></ul></section>
        <details v-if="data?.trends" class="rounded-xl border border-slate-200 bg-white p-5"><summary class="cursor-pointer font-semibold">{{ t('reportTrends') }}</summary><div class="mt-4 grid gap-5 lg:grid-cols-2">
            <div><h3 class="mb-2 font-semibold">{{ t('reportMonthly') }}</h3><table class="w-full text-left text-sm"><thead><tr><th>{{ t('calendarMonth') }}</th><th>{{ t('register') }}</th><th class="text-right">{{ t('reportCount') }}</th></tr></thead><tbody><tr v-for="row in data.trends.monthly" :key="`${row.month}-${row.register}`" class="border-t border-slate-100"><td class="py-2 tabular-nums">{{ row.month }}</td><td>{{ t(row.register === 'controlled' ? 'controlled-documents' : row.register) }}</td><td class="text-right tabular-nums">{{ row.total }}</td></tr></tbody></table></div>
            <div v-for="key in ['category', 'department']" :key="key"><h3 class="mb-2 font-semibold">{{ t(key === 'category' ? 'searchCategory' : 'department') }}</h3><dl class="flex flex-col gap-2"><div v-for="(row, index) in data.trends[`${key}_en`]" :key="index" class="flex justify-between gap-3 border-b border-slate-100 py-2 text-sm"><dt>{{ row[`${key}_${language === 'lo' ? 'lo' : 'en'}`] || row[`${key}_en`] || t('siteCorrespondence') }}</dt><dd class="font-semibold tabular-nums">{{ row.total }}</dd></div></dl></div>
        </div></details>
        <section class="flex flex-col gap-3"><div><h2 class="text-lg font-semibold">{{ t('dueCorrespondence') }}</h2><p class="text-sm text-slate-500">{{ t('dueSoonHelp') }}</p></div>
            <DataTable :pagination="false" :columns="dueColumns" :rows="data?.due ?? []" :caption="t('dueCorrespondence')" :loading="loading" :error="error" row-key="href">
                <template #cell-subject="{ row }"><a :href="row.href" class="font-medium text-teal-800 hover:underline">{{ row.subject }}</a></template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status" :label="t(`${row.kind}Status-${row.status}`)" /></template>
            </DataTable>
        </section>
        <section class="flex flex-col gap-3"><h2 class="text-lg font-semibold">{{ t('recentCorrespondence') }}</h2>
            <DataTable :pagination="false" :columns="columns" :rows="data?.recent ?? []" :caption="t('recentCorrespondence')" :loading="loading" :error="error" row-key="href">
                <template #cell-kind="{ row }">{{ t(row.kind) }}</template>
                <template #cell-subject="{ row }"><a :href="row.href" class="font-medium text-teal-800 hover:underline">{{ row.subject }}</a><span class="block font-mono text-xs text-slate-500">{{ row.number ?? t('status-draft') }}</span></template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status" :label="t(`${row.kind}Status-${row.status}`)" /></template>
            </DataTable>
        </section>
    </template>
</template>
