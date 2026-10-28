<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { useLocalization } from '../localization/index.js';
import { createJsonClient } from '../utilities/jsonClient.js';
import { formatDateTime } from '../utilities/dates.js';
import UiButton from './ui/UiButton.vue';
import CheckboxInput from './ui/CheckboxInput.vue';
import SelectInput from './ui/SelectInput.vue';
import TextareaInput from './ui/TextareaInput.vue';
import DataTable from './ui/DataTable.vue';
import ConfirmDialog from './ui/ConfirmDialog.vue';

const props = defineProps({ scope: { type: Object, required: true }, endpoint: { type: String, required: true }, csrfToken: { type: String, required: true } });
const emit = defineEmits(['unauthorized']);
const { t, language } = useLocalization();
const request = createJsonClient(props.csrfToken);
const rows = ref([]), roles = ref([]), history = ref([]), total = ref(0), page = ref(1), historyRegister = ref('');
const editor = ref(null), busy = ref(false), confirming = ref(false), error = ref(''), saved = ref(false);
const form = reactive({ enabled: false, count: '0', role_ids: ['', '', ''], change_reason: '' });
const canManage = computed(() => ['master-data.manage', 'roles.view'].every(key => props.scope.permissions.includes(key)));
const label = role => role[language.value === 'lo' ? 'name_lo' : 'name_en'];
const registerLabel = key => t(key === 'controlled' ? 'controlled-documents' : key);
const roleOptions = computed(() => [{ value: '', label: t('uiChoose') }, ...roles.value.map(role => ({ value: role.id, label: `${label(role)} (${role.code})` }))]);
const countOptions = computed(() => [0, 1, 2, 3].map(value => ({ value: String(value), label: String(value) })));
const columns = computed(() => [{ key: 'version', label: t('workflowVersion') }, { key: 'state', label: t('uiStatus') }, { key: 'approvers', label: t('workflowLevels') }, { key: 'created_by', label: t('controlledActor') }, { key: 'timestamp', label: t('reportTimestamp') }, { key: 'change_reason', label: t('controlledReason') }]);
const historyRows = computed(() => history.value.map(row => ({ ...row, state: t(row.enabled ? 'workflowEnabled' : 'workflowDisabled'), approvers: row.levels.map(item => `${item.level}: ${label(item.role)} (${item.role.code})`).join(' → '), timestamp: formatDateTime(row.created_at) })));
let controller;
function scopeData() { return { scope_type: props.scope.scope_type, company_id: props.scope.company_id, site_id: props.scope.site_id }; }
function url(path = '', extra = {}) { return `${props.endpoint}${path}?${new URLSearchParams(Object.entries({ ...scopeData(), ...extra }).filter(([, value]) => value !== null && value !== undefined))}`; }
function fail(failure) {
    const message = Object.values(failure.fields ?? {}).flat().find(value => value.startsWith('workflow'));
    error.value = message ?? 'workflowRequestFailed';
    if ([401, 403, 404, 419].includes(failure.status)) {
        rows.value = []; roles.value = []; history.value = []; editor.value = null; historyRegister.value = ''; total.value = 0; confirming.value = false;
        if (failure.status !== 404) { controller?.abort(); emit('unauthorized', failure); }
    }
}
async function load(signal) {
    const result = await request(url(), { signal });
    if (!signal.aborted) { rows.value = result.data; }
    if (canManage.value) {
        const choices = await request(url('/roles'), { signal });
        if (!signal.aborted) { roles.value = choices.data; }
    }
}
async function reset() {
    controller?.abort(); controller = new AbortController(); const signal = controller.signal;
    rows.value = []; roles.value = []; history.value = []; total.value = 0; page.value = 1; historyRegister.value = ''; editor.value = null; confirming.value = false; error.value = ''; saved.value = false; busy.value = true;
    Object.assign(form, { enabled: false, count: '0', role_ids: ['', '', ''], change_reason: '' });
    try { await load(signal); } catch (failure) { if (!signal.aborted) { fail(failure); } }
    finally { if (!signal.aborted) { busy.value = false; } }
}
function edit(row) {
    editor.value = row; error.value = ''; saved.value = false;
    Object.assign(form, { enabled: row.enabled, count: String(row.levels.length), role_ids: [0, 1, 2].map(index => row.levels[index]?.role.id ?? ''), change_reason: '' });
}
async function save() {
    if (busy.value || !canManage.value || !editor.value) { return; }
    const signal = controller.signal; busy.value = true; error.value = ''; saved.value = false;
    try {
        await request(`${props.endpoint}/${editor.value.register_key}`, { method: 'POST', signal, body: { ...scopeData(), expected_version: editor.value.version, enabled: form.enabled, sequence: 'sequential', role_ids: form.role_ids.slice(0, Number(form.count)).map(Number), change_reason: form.change_reason } });
        if (!signal.aborted) { editor.value = null; confirming.value = false; history.value = []; historyRegister.value = ''; await load(signal); if (!signal.aborted) { saved.value = true; } }
    } catch (failure) { if (!signal.aborted) { confirming.value = false; fail(failure); } }
    finally { if (!signal.aborted) { busy.value = false; } }
}
async function loadHistory(register = historyRegister.value) {
    if (!register || busy.value) { return; }
    const signal = controller.signal;
    if (register !== historyRegister.value) { page.value = 1; }
    historyRegister.value = register; history.value = []; error.value = ''; busy.value = true;
    try { const result = await request(url(`/${register}/history`, { page: page.value }), { signal }); if (!signal.aborted) { history.value = result.data; total.value = result.total; } }
    catch (failure) { if (!signal.aborted) { fail(failure); } }
    finally { if (!signal.aborted) { busy.value = false; } }
}
watch(() => props.scope.scope_key, reset, { immediate: true });
onBeforeUnmount(() => controller?.abort());
</script>

<template>
    <section class="flex flex-col gap-4" aria-labelledby="workflow-title" :aria-busy="busy">
        <h3 id="workflow-title" class="font-semibold">{{ t('workflowTitle') }}</h3>
        <p class="rounded-lg bg-amber-50 p-4 text-sm text-amber-900">{{ t('workflowNotice') }}</p>
        <p v-if="error" role="alert">{{ t(error) }}</p>
        <p v-if="saved" role="status">{{ t('workflowSaved') }}</p>
        <div class="grid gap-4 lg:grid-cols-3">
            <section v-for="row in rows" :key="row.register_key" class="flex flex-col gap-3 rounded-xl border border-slate-200 p-4">
                <h4 class="font-semibold">{{ registerLabel(row.register_key) }}</h4>
                <p>{{ t(row.enabled ? 'workflowEnabled' : 'workflowDisabled') }} · {{ t('workflowVersion') }} {{ row.version }} · {{ t('workflowSequential') }}</p>
                <ol class="flex flex-col gap-2"><li v-for="item in row.levels" :key="item.level">{{ t('workflowLevel') }} {{ item.level }}: {{ label(item.role) }} ({{ item.role.code }})</li></ol>
                <div class="flex flex-wrap gap-2"><UiButton v-if="canManage" variant="secondary" :disabled="busy" @click="edit(row)">{{ t('rbacEdit') }}</UiButton><UiButton variant="secondary" :disabled="busy" @click="loadHistory(row.register_key)">{{ t('workflowHistory') }}</UiButton></div>
            </section>
        </div>
        <form v-if="editor && canManage" class="flex flex-col gap-4 rounded-xl border border-slate-200 p-4" @submit.prevent="confirming = true">
            <h4 class="font-semibold">{{ registerLabel(editor.register_key) }}</h4>
            <fieldset :disabled="busy" class="grid gap-4 sm:grid-cols-2">
                <CheckboxInput id="workflow-enabled" v-model="form.enabled" :label="t('workflowEnabled')" />
                <SelectInput id="workflow-count" v-model="form.count" :label="t('workflowLevels')" :options="countOptions" />
                <SelectInput v-for="index in Number(form.count)" :id="`workflow-role-${index}`" :key="index" v-model="form.role_ids[index - 1]" :label="`${t('workflowLevel')} ${index}`" :options="roleOptions" required />
                <TextareaInput id="workflow-reason" v-model="form.change_reason" :label="t('controlledReason')" maxlength="500" required />
            </fieldset>
            <p class="text-sm text-slate-600">{{ t('workflowRoleHelp') }}</p>
            <div class="flex gap-2"><UiButton type="submit" :disabled="busy">{{ t('uiConfirm') }}</UiButton><UiButton variant="secondary" :disabled="busy" @click="editor = null">{{ t('uiCancel') }}</UiButton></div>
        </form>
        <section v-if="historyRegister" class="flex flex-col gap-3">
            <h4 class="font-semibold">{{ t('workflowHistory') }} · {{ registerLabel(historyRegister) }}</h4>
            <DataTable v-model:page="page" :rows="historyRows" :columns="columns" :caption="t('workflowHistory')" :loading="busy" server-side :total="total" :per-page="10" @change="loadHistory()" />
        </section>
        <ConfirmDialog v-model="confirming" :title="t('workflowTitle')" :message="t('workflowSaveConfirm')" :loading="busy" @confirm="save" />
    </section>
</template>
