<script setup>
import { nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import { useLocalization } from '../../localization/index.js';
import UiButton from './UiButton.vue';

const props = defineProps({
    title: { type: String, required: true },
    message: { type: String, required: true },
    danger: Boolean,
    loading: Boolean,
    confirmLabel: { type: String, default: '' },
});
const open = defineModel({ type: Boolean, default: false });
const emit = defineEmits(['confirm', 'cancel']);
const { t } = useLocalization();
const dialog = ref(null);
const dialogId = useId();
let previousFocus;

watch(open, async (value, previous, onCleanup) => {
    let cancelled = false;
    onCleanup(() => { cancelled = true; });
    await nextTick();

    if (cancelled || !dialog.value) {
        return;
    }
    if (value && !dialog.value.open) {
        previousFocus = document.activeElement;
        dialog.value.showModal();
        dialog.value.querySelector('[data-cancel]')?.focus();
    } else if (!value && dialog.value.open) {
        dialog.value.close();
        restoreFocus();
    }
}, { immediate: true });

function restoreFocus() {
    if (previousFocus?.isConnected) {
        previousFocus.focus();
    }
}

function cancel() {
    if (!props.loading) {
        open.value = false;
        emit('cancel');
    }
}

function confirm() {
    if (!props.loading) {
        emit('confirm');
    }
}

function handleClose() {
    open.value = false;
    restoreFocus();
}

onBeforeUnmount(() => {
    dialog.value?.close();
    restoreFocus();
});
</script>

<template>
    <dialog ref="dialog" :aria-labelledby="`${dialogId}-title`" :aria-describedby="`${dialogId}-message`" :aria-busy="loading" aria-modal="true" class="m-auto w-[calc(100%_-_2rem)] max-w-lg rounded-2xl border border-slate-200 bg-white p-6 text-slate-800 shadow-xl backdrop:bg-slate-950/50" @cancel.prevent="cancel" @close="handleClose">
        <h2 :id="`${dialogId}-title`" class="text-lg font-semibold">{{ title }}</h2>
        <p :id="`${dialogId}-message`" class="mt-3 text-sm leading-7 text-slate-600">{{ message }}</p>
        <div class="mt-6 flex flex-wrap justify-end gap-3">
            <UiButton data-cancel variant="secondary" :disabled="loading" @click="cancel">{{ t('uiCancel') }}</UiButton>
            <UiButton :variant="danger ? 'danger' : 'primary'" :loading="loading" @click="confirm">{{ confirmLabel || t('uiConfirm') }}</UiButton>
        </div>
    </dialog>
</template>
