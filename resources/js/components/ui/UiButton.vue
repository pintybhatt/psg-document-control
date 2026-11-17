<script setup>
import { Icon } from '@iconify/vue';
import { useAttrs, useSlots } from 'vue';
import { useLocalization } from '../../localization/index.js';
import { buttonIcons, iconForButton } from './buttonIcons.js';

const props = defineProps({
    variant: { type: String, default: 'primary', validator: value => ['primary', 'secondary', 'danger'].includes(value) },
    type: { type: String, default: 'button' },
    // Icon name from buttonIcons (e.g. "save"), an Iconify icon object, or false to hide it.
    // When omitted, the icon is chosen from the button's label.
    icon: { type: [String, Object, Boolean], default: null },
    loading: Boolean,
    disabled: Boolean,
});
const emit = defineEmits(['click']);
const { t } = useLocalization();
const slots = useSlots();
const attrs = useAttrs();
const variants = {
    primary: 'border-teal-700 bg-teal-700 text-white hover:bg-teal-800',
    secondary: 'border-slate-300 bg-white text-slate-700 hover:bg-slate-100',
    danger: 'border-red-700 bg-red-700 text-white hover:bg-red-800',
};

function textOf(nodes) {
    return (nodes ?? []).map(node => {
        if (typeof node === 'string') { return node; }
        if (typeof node?.children === 'string') { return node.children; }
        return Array.isArray(node?.children) ? textOf(node.children) : '';
    }).join('');
}
const label = () => textOf(slots.default?.()).replace(/\s+/g, ' ').trim();
function resolvedIcon(text) {
    if (props.icon === false) { return null; }
    if (props.icon && typeof props.icon === 'object') { return props.icon; }
    if (typeof props.icon === 'string') { return buttonIcons[props.icon] ?? null; }
    return iconForButton(text, props);
}

function handleClick(event) {
    if (!props.disabled && !props.loading) {
        emit('click', event);
    }
}
</script>

<template>
    <button :type="type" :disabled="disabled || loading" :title="attrs.title ?? (label() || undefined)" :aria-busy="loading ? 'true' : undefined" class="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border px-4 py-2 text-sm font-semibold disabled:cursor-not-allowed disabled:opacity-50" :class="variants[variant]" @click="handleClick">
        <span v-if="loading" aria-hidden="true" class="size-4 animate-spin rounded-full border-2 border-current border-r-transparent motion-reduce:animate-none"></span>
        <span v-if="loading" class="sr-only">{{ t('uiLoading') }}</span>
        <Icon v-else-if="resolvedIcon(label())" :icon="resolvedIcon(label())" aria-hidden="true" data-button-icon class="size-4 shrink-0" />
        <slot />
    </button>
</template>
