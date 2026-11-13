<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';
import { useLocalization } from '../localization/index.js';
import { createJsonClient } from '../utilities/jsonClient.js';
import { showSuccess } from '../utilities/alerts.js';
import { formatDateTime } from '../utilities/dates.js';
import UiButton from './ui/UiButton.vue';
import TextareaInput from './ui/TextareaInput.vue';

const props = defineProps({ execution: { type: Object, required: true }, scope: { type: Object, required: true }, csrfToken: { type: String, required: true }, kind: { type: String, required: true } });
const emit = defineEmits(['saved', 'failure']);
const { t, language } = useLocalization();
const request = createJsonClient(props.csrfToken);
const state = ref(null), history = ref([]), busy = ref(false), error = ref(''), comments = ref('');
let controller;
function scopeData() { return { company_id: props.scope.company_id, site_id: props.scope.site_id, department_id: props.scope.department_id, revision_id: props.execution.revision_id }; }
function url() { return `${props.execution.url}?${new URLSearchParams(Object.entries(scopeData()).filter(([, value]) => value != null))}`; }
function fail(failure) {
    error.value = Object.values(failure.fields ?? {}).flat().find(value => /^(approval|controlled|rbac)/.test(value)) ?? 'approvalRequestFailed';
    if ([401, 403, 404, 419].includes(failure.status)) { state.value = null; history.value = []; comments.value = ''; emit('failure', failure); }
}
async function load() {
    controller?.abort(); controller = new AbortController(); const signal = controller.signal;
    state.value = null; history.value = []; comments.value = ''; error.value = ''; busy.value = true;
    try { const result = await request(url(), { signal }); if (!signal.aborted) { state.value = result.data; history.value = result.history; } }
    catch (failure) { if (!signal.aborted) { fail(failure); } }
    finally { if (!signal.aborted) { busy.value = false; } }
}
async function decide(decision) {
    if (busy.value || !state.value) { return; }
    const signal = controller.signal; busy.value = true; error.value = '';
    const body = { ...scopeData(), decision, comments: comments.value || null, content_hash: state.value.content_hash, expected_event_id: state.value.expected_event_id };
    if (props.kind === 'controlled') { body.lock_version = state.value.lock_version; }
    try {
        await request(props.execution.url, { method: 'POST', signal, body });
        if (!signal.aborted) { showSuccess(t('uiSaveSuccess')); emit('saved'); }
    } catch (failure) { if (!signal.aborted) { fail(failure); } }
    finally { if (!signal.aborted) { busy.value = false; } }
}
watch(() => [props.execution.url, props.execution.revision_id, props.scope.unit_key ?? props.scope.scope_key], load, { immediate: true });
onBeforeUnmount(() => controller?.abort());
</script>

<template>
    <section class="flex flex-col gap-3 rounded-xl border border-slate-200 p-4" :aria-busy="busy">
        <h3 class="font-semibold">{{ t('approvalExecutionTitle') }}</h3>
        <div><UiButton variant="secondary" :disabled="busy" @click="load">{{ t('approvalRefresh') }}</UiButton></div>
        <p v-if="kind === 'incoming'" class="text-sm text-amber-900">{{ t('approvalIncomingPending') }}</p>
        <p v-if="error" role="alert">{{ t(error) }}</p>
        <template v-if="state">
            <p role="status">{{ t(`approvalStatus-${state.status}`) }} · {{ t('workflowVersion') }} {{ state.configuration_version }}<span v-if="state.current_level"> · {{ t('workflowLevel') }} {{ state.current_level }} / {{ state.levels.length }}</span></p>
            <ol class="flex flex-col gap-1 text-sm"><li v-for="item in state.levels" :key="item.level">{{ t('workflowLevel') }} {{ item.level }}: {{ item.role[language === 'lo' ? 'name_lo' : 'name_en'] }} ({{ item.role.code }})</li></ol>
            <template v-if="state.can_submit || state.can_decide">
                <TextareaInput id="approval-comments" v-model="comments" :label="t('controlledApprovalComments')" maxlength="5000" :disabled="busy" />
                <div class="flex flex-wrap gap-2"><UiButton v-if="state.can_submit" :disabled="busy" @click="decide('submitted')">{{ t('approvalSubmit') }}</UiButton><template v-if="state.can_decide"><UiButton :disabled="busy" @click="decide('approved')">{{ t('approvalApprove') }}</UiButton><UiButton variant="secondary" :disabled="busy" @click="decide('rejected')">{{ t('approvalReject') }}</UiButton></template></div>
            </template>
            <template v-if="kind !== 'controlled'"><h4 class="font-semibold">{{ t('controlledApprovalHistory') }}</h4><article v-for="entry in history" :key="`${entry.submission_id}:${entry.id}`" class="rounded-lg border border-slate-200 p-3 text-sm"><p>{{ t(`controlledApproval-${entry.decision}`) }} · {{ t('workflowLevel') }} {{ entry.level }} · {{ t('workflowVersion') }} {{ entry.configuration_version }} · {{ t('controlledActor') }} {{ entry.actor_id }} · {{ formatDateTime(entry.recorded_at) }}</p><p class="whitespace-pre-wrap wrap-anywhere">{{ entry.comments || '—' }}</p></article></template>
        </template>
    </section>
</template>
