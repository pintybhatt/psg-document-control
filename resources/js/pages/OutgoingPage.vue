<script setup>
import { computed, onBeforeUnmount, onMounted } from 'vue';
import Correspondence from '../components/OutgoingCorrespondence.vue';
import SelectInput from '../components/ui/SelectInput.vue';
import { useLocalization } from '../localization/index.js';
import { createWorkspace, useWorkspace } from '../utilities/workspace.js';

const props = defineProps({ endpoints: { type: Object, required: true }, csrfToken: { type: String, required: true } });
const { t, language } = useLocalization();
const shared = useWorkspace();
const workspace = shared ?? createWorkspace('standalone', props.endpoints.contexts, props.csrfToken);
const { selected, unitKey, units, loading, error } = workspace;
const label = item => item?.[language.value === 'lo' ? 'name_lo' : 'name_en'] ?? '';
const options = computed(() => units.value.map(item => ({ value: item.key, label: label(item.department) || t('siteCorrespondence') })));
onMounted(() => { if (!shared) { workspace.refresh(); } });
onBeforeUnmount(() => { if (!shared) { workspace.revoke(); } });
</script>

<template>
    <section class="flex flex-col gap-4">
        <p v-if="error && !shared" role="alert">{{ t(error) }}</p>
        <SelectInput v-if="units.length > 1" id="outgoing-context" v-model="unitKey" :label="t('outgoingIssuingDepartment')" :options="options" :disabled="loading" class="max-w-sm" />
        <p v-if="loading" role="status">{{ t('uiLoading') }}</p>
        <p v-else-if="!selected && !error" class="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-600">{{ t('outgoingNoContexts') }}</p>
        <Correspondence v-if="selected" :key="selected.key" :scope="selected" :endpoint="endpoints.endpoint" :incoming-endpoint="endpoints.incoming" :csrf-token="csrfToken" @unauthorized="workspace.revoke" />
    </section>
</template>
