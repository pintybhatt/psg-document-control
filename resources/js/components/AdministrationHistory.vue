<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import SelectInput from './ui/SelectInput.vue';
import DataTable from './ui/DataTable.vue';
import { useLocalization } from '../localization/index.js';
import { createJsonClient } from '../utilities/jsonClient.js';
import { formatDateTime } from '../utilities/dates.js';

const props = defineProps({ scope: { type: Object, required: true }, endpoint: { type: String, required: true }, csrfToken: { type: String, required: true }, canManageOrganization: { type: Boolean, default: false } });
const emit = defineEmits(['unauthorized']);
const { t, language } = useLocalization();
const request = createJsonClient(props.csrfToken);
const permissions = { 'master-data': 'master-data.view', numbering: 'numbering.view', roles: 'roles.view', assignments: 'assignments.view', memberships: 'memberships.view', organization: 'security.audit.view' };
const sources = computed(() => Object.keys(permissions).filter(key => (props.scope.scope_type !== 'global' || ['roles', 'assignments', 'organization'].includes(key)) && (key !== 'organization' || (props.scope.scope_type === 'global' && props.canManageOrganization)) && props.scope.permissions.includes(permissions[key])));
const options = computed(() => sources.value.map(value => ({ value, label: t(`adminSource-${value}`) })));
const source = ref('');
const rows = ref([]);
const page = ref(1);
const total = ref(0);
const loading = ref(false);
const error = ref('');
let controller;
const columns = computed(() => [
    { key: 'timestamp', label: t('adminTimestamp') }, { key: 'actor', label: t('adminActor') },
    { key: 'action', label: t('adminAction') }, { key: 'subject', label: t('adminSubject') }, { key: 'changes', label: t('adminChanges') },
]);
const displayRows = computed(() => rows.value.map(row => ({ ...row, actor: row.actor ?? t('adminSystem'), timestamp: formatDateTime(row.timestamp), action: (language.value === 'lo' ? row.event_lo : row.event_en) || t(`adminAction-${row.action}`), subject: `${t(`adminSubject-${row.subject_type}`)} #${row.subject_id}` })));
const fieldLabels = { code: 'rbacCode', name_en: 'rbacNameEn', name_lo: 'rbacNameLo', active: 'rbacActive', prefix: 'numberingPrefix', padding: 'numberingPadding', version: 'adminVersion', change_reason: 'numberingReason', permission_keys: 'adminPermissions', register_ids: 'adminRegisterIds', user_id: 'adminAccountId', role_id: 'adminRoleId', review_enabled: 'masterReviewEnabled', review_interval_months: 'masterReviewMonths', review_baseline: 'masterReviewBaseline' };
const displayValue = (value, field) => field === 'review_baseline' && value === 'completion_date' ? t('masterReviewCompletionBaseline') : value === null ? '—' : typeof value === 'boolean' ? t(value ? 'rbacActive' : 'rbacInactive') : Array.isArray(value) ? value.join(', ') : String(value);
async function load() {
    controller?.abort();
    controller = new AbortController();
    const signal = controller.signal;
    rows.value = []; total.value = 0; error.value = '';
    if (!source.value) { loading.value = false; return; }
    loading.value = true;
    const scope = props.scope;
    const parameters = new URLSearchParams({ scope_type: scope.scope_type, source: source.value, page: page.value, per_page: 10 });
    if (scope.company_id) { parameters.set('company_id', scope.company_id); }
    if (scope.site_id) { parameters.set('site_id', scope.site_id); }
    try {
        const response = await request(`${props.endpoint}?${parameters}`, { signal });
        if (!signal.aborted) { rows.value = response.data; total.value = response.meta.total; }
    } catch (failure) {
        if (failure.name === 'AbortError' || signal.aborted) { return; }
        error.value = 'rbacRequestFailed';
        if ([401, 403, 419].includes(failure.status)) { emit('unauthorized', failure); }
    } finally { if (!signal.aborted) { loading.value = false; } }
}
watch(() => props.scope, () => {
    page.value = 1;
    const previous = source.value;
    source.value = sources.value[0] ?? '';
    if (source.value === previous) { load(); }
}, { immediate: true });
watch(source, () => { page.value = 1; load(); }, { immediate: true });
onBeforeUnmount(() => controller?.abort());
</script>

<template>
    <section class="flex flex-col gap-4" aria-labelledby="admin-history-heading">
        <h3 id="admin-history-heading" class="font-semibold">{{ t('adminHistory') }}</h3>
        <p class="text-sm text-slate-600">{{ t('adminHistoryNotice') }}</p>
        <SelectInput v-model="source" :label="t('adminHistorySource')" :options="options" />
        <p v-if="error" role="alert" class="text-sm text-red-700">{{ t(error) }}</p>
        <DataTable v-model:page="page" :rows="displayRows" :columns="columns" :caption="t('adminHistory')" :loading="loading" server-side :total="total" :per-page="10" @change="load">
            <template #cell-changes="{ row }">
                <details v-if="row.changes.length"><summary class="cursor-pointer text-teal-800">{{ t('adminChanges') }}</summary>
                    <dl class="mt-2 flex flex-col gap-2"><div v-for="change in row.changes" :key="change.field"><dt class="font-medium">{{ t(fieldLabels[change.field]) }}</dt><dd class="wrap-anywhere">{{ displayValue(change.before, change.field) }} → {{ displayValue(change.after, change.field) }}</dd></div></dl>
                </details><span v-else>—</span>
            </template>
        </DataTable>
    </section>
</template>
