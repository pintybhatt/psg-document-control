<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import IncomingCorrespondence from '../components/IncomingCorrespondence.vue';
import SelectInput from '../components/ui/SelectInput.vue';
import { useLocalization } from '../localization/index.js';
import { createJsonClient } from '../utilities/jsonClient.js';

const props = defineProps({ endpoints: { type: Object, required: true }, csrfToken: { type: String, required: true } });
const { t, language } = useLocalization();
const contexts = ref([]);
const selectedKey = ref('');
const error = ref('');
const loading = ref(true);
const controller = new AbortController();
const selected = computed(() => contexts.value.find(item => item.key === selectedKey.value));
const label = item => item?.[language.value === 'lo' ? 'name_lo' : 'name_en'] ?? '';
const options = computed(() => [{ value: '', label: t('uiChoose') }, ...contexts.value.map(item => ({ value: item.key, label: [item.company, item.site, item.department].filter(Boolean).map(label).join(' / ') }))]);
function revoke() { contexts.value = []; selectedKey.value = ''; error.value = 'rbacForbidden'; }
onMounted(async () => {
    try {
        const result = await createJsonClient(props.csrfToken)(props.endpoints.contexts, { signal: controller.signal });
        if (!controller.signal.aborted) { contexts.value = result.data; }
    } catch (failure) { if (failure.name !== 'AbortError') { error.value = failure.message; } }
    finally { loading.value = false; }
});
onBeforeUnmount(() => controller.abort());
</script>

<template>
    <section class="flex flex-col gap-6">
        <h1 class="text-2xl font-semibold">{{ t('incomingTitle') }}</h1>
        <p v-if="error" role="alert">{{ t(error) }}</p>
        <SelectInput id="incoming-context" v-model="selectedKey" :label="t('incomingReceivingUnit')" :options="options" :disabled="loading" />
        <p v-if="!loading && !contexts.length && !error">{{ t('incomingNoContexts') }}</p>
        <IncomingCorrespondence v-if="selected" :scope="selected" :endpoint="endpoints.endpoint" :csrf-token="csrfToken" @unauthorized="revoke" />
    </section>
</template>
