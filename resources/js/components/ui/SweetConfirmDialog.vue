<script setup>
import { watch } from 'vue';
import Swal from 'sweetalert2';
import { swalClasses, swalDangerClasses } from '../../utilities/alerts.js';
import { useLocalization } from '../../localization/index.js';

// Same interface as ConfirmDialog, shown with SweetAlert2. The parent's save shows the busy spinner.
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
let showing = false;

async function show() {
    showing = true;
    const result = await Swal.fire({
        icon: props.danger ? 'warning' : 'question',
        title: props.title,
        text: props.message,
        showCancelButton: true,
        reverseButtons: true,
        focusCancel: props.danger,
        confirmButtonText: props.confirmLabel || t('uiConfirm'),
        cancelButtonText: t('uiCancel'),
        buttonsStyling: false,
        customClass: props.danger ? swalDangerClasses : swalClasses,
    });
    showing = false;
    if (!open.value) {
        return;
    }
    open.value = false;
    emit(result.isConfirmed ? 'confirm' : 'cancel');
}

watch(open, (value) => {
    if (value && !showing) {
        show();
    } else if (!value && showing) {
        Swal.close();
    }
}, { immediate: true });
</script>

<template>
    <span hidden></span>
</template>
