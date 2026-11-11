<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, useId } from 'vue';
import { Icon } from '@iconify/vue';
import xIcon from '@iconify-icons/flowbite/close-outline';
import { useLocalization } from '../../localization/index.js';

const props = defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
    busy: Boolean,
    size: { type: String, default: 'lg', validator: value => ['md', 'lg', 'xl'].includes(value) },
});
const emit = defineEmits(['close']);
const { t } = useLocalization();
const panel = ref(null);
const titleId = `${useId()}-title`;
const widths = { md: 'max-w-xl', lg: 'max-w-3xl', xl: 'max-w-5xl' };
const focusableSelector = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
let previousFocus;
let previousOverflow;

function close() {
    if (!props.busy) {
        emit('close');
    }
}

function handleKeydown(event) {
    if (event.key === 'Escape') {
        event.stopPropagation();
        close();
        return;
    }
    if (event.key !== 'Tab') {
        return;
    }
    const focusable = [...panel.value.querySelectorAll(focusableSelector)];
    if (!focusable.length) {
        event.preventDefault();
        return;
    }
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

onMounted(async () => {
    previousFocus = document.activeElement;
    previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    await nextTick();
    if (panel.value && !panel.value.contains(document.activeElement)) {
        panel.value.focus();
    }
});

onBeforeUnmount(() => {
    document.body.style.overflow = previousOverflow ?? '';
    if (previousFocus?.isConnected) {
        previousFocus.focus();
    }
});
</script>

<template>
    <div data-form-modal class="fixed inset-0 z-50 flex justify-center overflow-y-auto bg-slate-950/50 p-4 sm:p-6" @mousedown.self="close">
        <div ref="panel" role="dialog" aria-modal="true" :aria-labelledby="titleId" :aria-busy="busy" tabindex="-1" class="my-auto flex max-h-[calc(100dvh-2rem)] w-full flex-col overflow-hidden rounded-2xl bg-white text-slate-800 shadow-2xl ring-1 ring-slate-900/10 outline-none sm:max-h-[calc(100dvh-3rem)]" :class="widths[size]" @keydown="handleKeydown">
            <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-6 py-4">
                <div class="min-w-0">
                    <h3 :id="titleId" class="text-lg font-semibold text-slate-900 wrap-anywhere">{{ title }}</h3>
                    <p v-if="subtitle" class="mt-1 text-sm text-slate-500 wrap-anywhere">{{ subtitle }}</p>
                </div>
                <button type="button" data-modal-close :aria-label="t('uiClose')" :title="t('uiClose')" :disabled="busy" class="-mr-2 shrink-0 rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-900 disabled:opacity-50" @click="close">
                    <Icon :icon="xIcon" aria-hidden="true" class="size-5" />
                </button>
            </header>
            <div class="overflow-y-auto px-6 py-5">
                <slot />
            </div>
        </div>
    </div>
</template>
