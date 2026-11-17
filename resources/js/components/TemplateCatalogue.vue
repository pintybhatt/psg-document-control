<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { createJsonClient } from '../utilities/jsonClient.js';
import { formatDateTime } from '../utilities/dates.js';
import { useLocalization } from '../localization/index.js';
import UiButton from './ui/UiButton.vue';
import IconButton from './ui/IconButton.vue';
import TextInput from './ui/TextInput.vue';
import TextareaInput from './ui/TextareaInput.vue';
import SelectInput from './ui/SelectInput.vue';
import DataTable from './ui/DataTable.vue';
import ConfirmDialog from './ui/SweetConfirmDialog.vue';
import FormModal from './ui/FormModal.vue';
import { showSuccess } from '../utilities/alerts.js';

const props = defineProps({ scope: { type: Object, required: true }, endpoint: { type: String, required: true }, csrfToken: { type: String, required: true } });
const emit = defineEmits(['unauthorized']);
const { t, language } = useLocalization();
const request = createJsonClient(props.csrfToken);
const rows = ref([]), sources = ref([]), history = ref([]), selected = ref(null), page = ref(1), total = ref(0);
const busy = ref(false), editing = ref(false), error = ref(''), search = ref(''), reason = ref(''), confirming = ref(false);
const form = reactive({ name_en: '', name_lo: '', description_en: '', description_lo: '', purpose: '', attachment_version_id: '' });
const canView = computed(() => props.scope.scope_type === 'site' && props.scope.can_access_templates !== false && props.scope.permissions.includes('templates.view'));
const canManage = computed(() => canView.value && props.scope.permissions.includes('templates.manage'));
const canPublish = computed(() => canManage.value && props.scope.permissions.includes('templates.publish'));
const confirmAction = ref('retire');
const columns = computed(() => [{ key: 'name', label: t('masterName') }, { key: 'purpose', label: t('templatePurpose') }, { key: 'state', label: t('uiStatus') }, { key: 'actions', label: t('rbacActions') }]);
const displayRows = computed(() => rows.value.map(row => ({ ...row, name: row[language.value === 'lo' ? 'name_lo' : 'name_en'], state: t(`templateStatus-${row.status}`) })));
const sourceOptions = computed(() => [{ value: '', label: t('templateNoSource') }, ...sources.value.map(row => ({ value: row.id, label: `${row.document_code} · ${row.revision} · ${row.original_name} · v${row.version}` }))]);
let controller;
function scopeData() { return { scope_type: props.scope.scope_type, company_id: props.scope.company_id, site_id: props.scope.site_id }; }
function url(path = '', extra = {}) { return `${props.endpoint}${path}?${new URLSearchParams({ ...scopeData(), page: page.value, ...extra })}`; }
function fail(failure) {
    if (failure.name === 'AbortError') { return; }
    error.value = Object.values(failure.fields ?? {}).flat().find(value => /^(template|storage)/.test(value)) ?? 'templateRequestFailed';
    if ([401, 403, 404, 419].includes(failure.status)) {
        rows.value = []; sources.value = []; history.value = []; selected.value = null; total.value = 0; editing.value = false; confirming.value = false;
        if (failure.status !== 404) { controller?.abort(); emit('unauthorized', failure); }
    }
}
async function load() {
    const signal = controller.signal;
    busy.value = true;
    try { const result = await request(url(), { signal }); if (!signal.aborted) { rows.value = result.data; total.value = result.meta.total; } }
    catch (failure) { if (!signal.aborted) { fail(failure); } }
    finally { if (!signal.aborted) { busy.value = false; } }
}
async function reset() {
    controller?.abort(); controller = new AbortController();
    rows.value = []; sources.value = []; history.value = []; selected.value = null; page.value = 1; total.value = 0; editing.value = false; confirming.value = false; error.value = ''; reason.value = ''; search.value = '';
    Object.keys(form).forEach(key => { form[key] = ''; });
    if (!canView.value) { busy.value = false; error.value = 'adminTemplateScopeRestricted'; return; }
    await load();
}
function create() { Object.keys(form).forEach(key => { form[key] = ''; }); sources.value = []; search.value = ''; editing.value = true; selected.value = null; history.value = []; error.value = ''; }
async function findSources() {
    const signal = controller.signal;
    busy.value = true; error.value = ''; sources.value = []; form.attachment_version_id = '';
    try { const result = await request(url('/sources', { q: search.value }), { signal }); if (!signal.aborted) { sources.value = result.data; } }
    catch (failure) { if (!signal.aborted) { fail(failure); } }
    finally { if (!signal.aborted) { busy.value = false; } }
}
async function details(row) {
    const signal = controller.signal;
    busy.value = true; error.value = ''; selected.value = null; history.value = []; editing.value = false; reason.value = '';
    try { const result = await request(url(`/${row.id}`), { signal }); if (!signal.aborted) { selected.value = result.data; history.value = result.history; } }
    catch (failure) { if (!signal.aborted) { fail(failure); } }
    finally { if (!signal.aborted) { busy.value = false; } }
}
async function save(action = 'create') {
    if (busy.value || !canManage.value || (action === 'publish' && !canPublish.value)) { return; }
    const signal = controller.signal;
    busy.value = true; error.value = '';
    try {
        const body = action !== 'create' ? { reason: reason.value } : { ...form, attachment_version_id: form.attachment_version_id || null };
        const result = await request(url(action !== 'create' ? `/${selected.value.id}/${action}` : ''), { method: 'POST', signal, body: { ...scopeData(), ...body } });
        if (!signal.aborted) { editing.value = false; confirming.value = false; showSuccess(t('uiSaveSuccess')); await details(result.data); if (!signal.aborted) { await load(); } }
    } catch (failure) { if (!signal.aborted) { confirming.value = false; fail(failure); } }
    finally { if (!signal.aborted) { busy.value = false; } }
}
watch(() => [props.scope.scope_key, canView.value], reset, { immediate: true });
onBeforeUnmount(() => controller?.abort());
</script>

<template>
    <section class="flex flex-col gap-4" aria-labelledby="template-title">
        <div class="flex flex-wrap items-center justify-between gap-3"><h3 id="template-title" class="font-semibold">{{ t('templateTitle') }}</h3><UiButton v-if="canManage" :disabled="busy" @click="create">{{ t('templateCreate') }}</UiButton></div>
        <p class="rounded-lg bg-amber-50 p-4 text-sm text-amber-900">{{ t('templateInactiveNotice') }}</p>
        <p v-if="error && !editing" role="alert" class="rounded-lg bg-red-50 p-3 text-sm text-red-800">{{ t(error) }}</p>
        <DataTable v-model:page="page" :rows="displayRows" :columns="columns" :caption="t('templateTitle')" server-side :total="total" :per-page="10" :loading="busy" @change="load"><template #cell-actions="{ row }"><IconButton action="view" :label="t('rbacDetails')" :disabled="busy" @click="details(row)" /></template></DataTable>
        <FormModal v-if="editing" :title="t('templateCreate')" :subtitle="t('templateImmutableHelp')" :busy="busy" @close="editing = false">
        <form class="flex flex-col gap-4" @submit.prevent="save()">
            <p v-if="error" role="alert" class="rounded-lg bg-red-50 p-3 text-sm text-red-800">{{ t(error) }}</p>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <TextInput id="template-name-en" v-model="form.name_en" :label="t('rbacNameEn')" required maxlength="255" :disabled="busy" />
                <TextInput id="template-name-lo" v-model="form.name_lo" :label="t('rbacNameLo')" required maxlength="255" :disabled="busy" />
                <TextareaInput id="template-description-en" v-model="form.description_en" :label="t('masterDescriptionEn')" maxlength="2000" :disabled="busy" />
                <TextareaInput id="template-description-lo" v-model="form.description_lo" :label="t('masterDescriptionLo')" maxlength="2000" :disabled="busy" />
                <div class="sm:col-span-2"><TextInput id="template-purpose" v-model="form.purpose" :label="t('templatePurpose')" :helper="t('templatePurposeHelp')" required maxlength="100" :disabled="busy" /></div>
            </div>
            <div class="grid grid-cols-1 items-start gap-4 rounded-xl border border-slate-200 bg-slate-50 p-4 sm:grid-cols-[minmax(0,1fr)_auto]">
                <TextInput id="template-source-search" v-model="search" :label="t('templateSourceSearch')" :helper="t('templateSourceHelp')" maxlength="100" :disabled="busy" />
                <UiButton variant="secondary" class="sm:mt-7" :disabled="busy || !search.trim()" @click="findSources">{{ t('templateFindSource') }}</UiButton>
                <div class="sm:col-span-2"><SelectInput id="template-source" v-model="form.attachment_version_id" :label="t('templateSource')" :options="sourceOptions" :disabled="busy" /></div>
            </div>
            <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 pt-4"><UiButton variant="secondary" :disabled="busy" @click="editing = false">{{ t('uiCancel') }}</UiButton><UiButton type="submit" :loading="busy">{{ t('uiConfirm') }}</UiButton></div>
        </form>
        </FormModal>
        <section v-if="selected" class="flex flex-col gap-3 rounded-xl border border-slate-200 p-4">
            <h4 class="font-semibold">{{ selected[language === 'lo' ? 'name_lo' : 'name_en'] }}</h4>
            <p>{{ selected.purpose }} · {{ t(`templateStatus-${selected.status}`) }}</p>
            <p class="whitespace-pre-wrap wrap-anywhere">{{ selected[language === 'lo' ? 'description_lo' : 'description_en'] }}</p>
            <p>{{ t(selected.attachment_version_id ? 'templateSourceReferenced' : 'templateNoSource') }}</p>
            <p v-if="selected.published_at" class="text-sm">{{ t('templatePublishedAt') }}: {{ formatDateTime(selected.published_at) }} · {{ t('controlledActor') }} {{ selected.published_by }}</p>
            <a v-if="selected.status === 'published' && selected.download_url" :href="selected.download_url" download class="font-semibold text-teal-800 hover:underline">{{ t('templateDownload') }}</a>
            <ul class="flex flex-col gap-2"><li v-for="entry in history" :key="entry.key ?? entry.id" class="text-sm"><p>{{ t(`templateHistory-${entry.action}`) }} · {{ formatDateTime(entry.created_at) }} · {{ t('controlledActor') }} {{ entry.actor_id }}</p><p class="whitespace-pre-wrap wrap-anywhere">{{ entry.reason }}</p></li></ul>
            <form v-if="canManage && (selected.status === 'inactive' || (selected.status === 'published' && canPublish))" class="flex flex-col gap-3" @submit.prevent="confirmAction = 'retire'; confirming = true"><TextareaInput id="template-retire-reason" v-model="reason" :label="t('controlledReason')" required maxlength="2000" :disabled="busy" /><UiButton type="submit" variant="secondary" :disabled="busy">{{ t('templateRetire') }}</UiButton></form>
            <form v-if="canPublish && selected.status === 'inactive' && selected.attachment_version_id" class="flex flex-col gap-3" @submit.prevent="confirmAction = 'publish'; confirming = true"><TextareaInput id="template-publish-reason" v-model="reason" :label="t('templatePublishReason')" required maxlength="2000" :disabled="busy" /><UiButton type="submit" :disabled="busy">{{ t('templatePublish') }}</UiButton></form>
        </section>
        <ConfirmDialog v-model="confirming" :title="t(confirmAction === 'publish' ? 'templatePublish' : 'templateRetire')" :message="t(confirmAction === 'publish' ? 'templatePublishConfirm' : 'templateRetireConfirm')" :loading="busy" @confirm="save(confirmAction)" @cancel="confirming = false" />
    </section>
</template>
