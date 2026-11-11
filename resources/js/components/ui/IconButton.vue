<script setup>
import { Icon } from '@iconify/vue';
import editIcon from '@iconify-icons/flowbite/edit-outline';
import eyeIcon from '@iconify-icons/flowbite/eye-outline';
import trashIcon from '@iconify-icons/flowbite/trash-bin-outline';
import banIcon from '@iconify-icons/flowbite/ban-outline';
import checkCircleIcon from '@iconify-icons/flowbite/check-circle-outline';

// Compact row-action button: icon only, with a tooltip and a screen-reader label.
defineProps({
    action: { type: String, required: true, validator: value => ['edit', 'view', 'delete', 'deactivate', 'activate'].includes(value) },
    label: { type: String, required: true },
    type: { type: String, default: 'button' },
    disabled: Boolean,
});

const icons = { edit: editIcon, view: eyeIcon, delete: trashIcon, deactivate: banIcon, activate: checkCircleIcon };
const styles = {
    edit: 'text-slate-500 hover:text-teal-700',
    view: 'text-slate-500 hover:text-teal-700',
    delete: 'text-red-500 hover:text-red-700',
    deactivate: 'text-amber-600 hover:text-amber-800',
    activate: 'text-teal-600 hover:text-teal-800',
};
</script>

<template>
    <button :type="type" :disabled="disabled" :title="label" :data-action="action" class="inline-flex shrink-0 items-center justify-center p-1 transition-colors disabled:cursor-not-allowed disabled:opacity-40" :class="styles[action]">
        <Icon :icon="icons[action]" aria-hidden="true" class="size-5" />
        <span class="sr-only">{{ label }}</span>
    </button>
</template>
