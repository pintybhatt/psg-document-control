<script setup>
import { computed, onBeforeUnmount, onMounted } from 'vue';
import Correspondence from '../components/ControlledDocuments.vue';
import { useLocalization } from '../localization/index.js';
import { createWorkspace, useWorkspace } from '../utilities/workspace.js';

const props = defineProps({ endpoints: { type: Object, required: true }, csrfToken: { type: String, required: true } });
const { t, language } = useLocalization();
const shared = useWorkspace();
const workspace = shared ?? createWorkspace('standalone', props.endpoints.contexts, props.csrfToken);
const { selected, unitKey, units, loading, error } = workspace;
const label = item => item?.[language.value === 'lo' ? 'name_lo' : 'name_en'] ?? '';
const options = computed(() => units.value.map(item => ({ value: item.key, label: label(item.department) || t('siteWideDepartment') })));
onMounted(() => { if (!shared) { workspace.refresh(); } });
onBeforeUnmount(() => { if (!shared) { workspace.revoke(); } });
</script>

<template>
    <section class="flex flex-col gap-4">
        <p v-if="error && !shared" role="alert">{{ t(error) }}</p>
        <p v-if="loading" role="status">{{ t('uiLoading') }}</p>
        <p v-else-if="!selected && !error" class="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-600">{{ t('controlledNoContexts') }}</p>
        <Correspondence v-if="selected" v-model:context-key="unitKey" :scope="selected" :department-options="options" :endpoint="endpoints.endpoint" :csrf-token="csrfToken" @unauthorized="workspace.revoke" />
    </section>
</template>
