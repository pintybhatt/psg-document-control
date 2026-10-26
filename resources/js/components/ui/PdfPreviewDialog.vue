<script setup>
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import { useLocalization } from '../../localization/index.js';
import { createJsonClient } from '../../utilities/jsonClient.js';
import UiButton from './UiButton.vue';

const props = defineProps({ url: { type: String, required: true }, csrfToken: { type: String, required: true }, filename: { type: String, required: true }, title: { type: String, required: true } });
const open = defineModel({ type: Boolean, default: false });
const emit = defineEmits(['failure']);
const { t } = useLocalization();
const dialog = ref(null);
const dialogId = useId();
const objectUrl = ref('');
const loading = ref(false);
const error = ref('');
const page = ref(1);
const pages = ref(1);
const zoom = ref('page-width');
const source = computed(() => objectUrl.value ? `${objectUrl.value}#page=${page.value}&zoom=${zoom.value}&toolbar=0&navpanes=0` : '');
let controller;
let previousFocus;

function release() {
    controller?.abort();
    if (objectUrl.value) { URL.revokeObjectURL(objectUrl.value); objectUrl.value = ''; }
}
function restoreFocus() { if (previousFocus?.isConnected) { previousFocus.focus(); } }
function close() { open.value = false; }
function handleClose() { open.value = false; release(); restoreFocus(); }
function download() {
    if (!objectUrl.value) { return; }
    const link = document.createElement('a');
    link.href = objectUrl.value; link.download = props.filename;
    document.body.append(link); link.click(); link.remove();
}
watch([open, () => props.url], async ([visible], previous, onCleanup) => {
    release(); error.value = ''; page.value = 1; pages.value = 1; zoom.value = 'page-width';
    const requestController = new AbortController(); controller = requestController;
    onCleanup(() => requestController.abort());
    if (!visible) { dialog.value?.close(); restoreFocus(); return; }
    loading.value = true;
    await nextTick();
    if (requestController.signal.aborted || !dialog.value) { return; }
    if (!dialog.value.open) {
        previousFocus = document.activeElement; dialog.value.showModal();
        dialog.value.querySelector('[data-close]')?.focus();
    }
    try {
        const result = await createJsonClient(props.csrfToken)(props.url, { signal: requestController.signal, responseType: 'blob' });
        if (!requestController.signal.aborted) {
            if (result.blob.type !== 'application/pdf') { throw new Error('rbacRequestFailed'); }
            objectUrl.value = URL.createObjectURL(result.blob);
            pages.value = Number.isInteger(result.pages) && result.pages > 0 ? result.pages : 1;
        }
    } catch (failure) {
        if (!requestController.signal.aborted) { error.value = failure.message; emit('failure', failure); }
    } finally { if (!requestController.signal.aborted) { loading.value = false; } }
}, { immediate: true });
onBeforeUnmount(() => { release(); dialog.value?.close(); restoreFocus(); });
</script>

<template>
    <dialog ref="dialog" :aria-labelledby="`${dialogId}-title`" :aria-busy="loading" aria-modal="true" class="m-auto h-[94dvh] w-[96vw] max-w-7xl overflow-hidden rounded-xl border border-slate-200 bg-slate-100 p-0 text-slate-800 shadow-xl backdrop:bg-slate-950/60" @cancel.prevent="close" @close="handleClose">
        <div class="flex h-full min-h-0 flex-col">
            <header class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-3">
                <h2 :id="`${dialogId}-title`" class="text-base font-semibold">{{ title }}</h2>
                <div class="flex flex-wrap gap-2"><UiButton :disabled="!objectUrl" @click="download">{{ t('reportDownloadPdf') }}</UiButton><UiButton data-close variant="secondary" @click="close">{{ t('reportClose') }}</UiButton></div>
            </header>
            <nav :aria-label="t('reportPreviewControls')" class="flex flex-wrap items-center justify-center gap-3 border-b border-slate-200 bg-white px-4 py-2">
                <UiButton variant="secondary" :disabled="!objectUrl || page <= 1" @click="page--">{{ t('uiPrevious') }}</UiButton>
                <span role="status" class="text-sm tabular-nums">{{ t('reportPageSummary', { page, pages }) }}</span>
                <UiButton variant="secondary" :disabled="!objectUrl || page >= pages" @click="page++">{{ t('uiNext') }}</UiButton>
                <label class="flex items-center gap-2 text-sm">{{ t('reportZoom') }}<select v-model="zoom" :disabled="!objectUrl" class="ui-control w-auto"><option value="page-width">{{ t('reportFitWidth') }}</option><option v-for="value in [50, 75, 100, 125, 150, 200]" :key="value" :value="String(value)">{{ value }}%</option></select></label>
            </nav>
            <p v-if="loading" role="status" class="p-8 text-center text-sm">{{ t('reportGenerating') }}</p>
            <p v-else-if="error" role="alert" class="m-4 rounded-lg bg-red-50 p-4 text-sm text-red-800">{{ t(error) }}</p>
            <iframe v-if="objectUrl" :key="source" :src="source" :title="title" class="min-h-0 w-full flex-1 border-0" />
        </div>
    </dialog>
</template>
