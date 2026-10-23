<script setup>
import { useLocalization } from '../../localization/index.js';

const props = defineProps({
    variant: { type: String, default: 'primary', validator: value => ['primary', 'secondary', 'danger'].includes(value) },
    type: { type: String, default: 'button' },
    loading: Boolean,
    disabled: Boolean,
});
const emit = defineEmits(['click']);
const { t } = useLocalization();
const variants = {
    primary: 'border-teal-700 bg-teal-700 text-white hover:bg-teal-800',
    secondary: 'border-slate-300 bg-white text-slate-700 hover:bg-slate-100',
    danger: 'border-red-700 bg-red-700 text-white hover:bg-red-800',
};

function handleClick(event) {
    if (!props.disabled && !props.loading) {
        emit('click', event);
    }
}
</script>

<template>
    <button :type="type" :disabled="disabled || loading" :aria-busy="loading ? 'true' : undefined" class="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border px-4 py-2 text-sm font-semibold disabled:cursor-not-allowed disabled:opacity-50" :class="variants[variant]" @click="handleClick">
        <span v-if="loading" aria-hidden="true" class="size-4 animate-spin rounded-full border-2 border-current border-r-transparent motion-reduce:animate-none"></span>
        <span v-if="loading" class="sr-only">{{ t('uiLoading') }}</span>
        <slot />
    </button>
</template>
