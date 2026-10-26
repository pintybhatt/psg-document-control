<script setup>
import { useLocalization } from '../../localization/index.js';

defineProps({ status: { type: String, required: true }, label: { type: String, default: '' } });
const { t } = useLocalization();
const styles = {
    received: 'border-teal-200 bg-teal-50 text-teal-900',
    assigned: 'border-blue-200 bg-blue-50 text-blue-800',
    in_progress: 'border-amber-200 bg-amber-50 text-amber-900',
    responded: 'border-emerald-200 bg-emerald-50 text-emerald-800',
    dispatched: 'border-blue-200 bg-blue-50 text-blue-800',
    delivered: 'border-emerald-200 bg-emerald-50 text-emerald-800',
    draft: 'border-slate-200 bg-slate-100 text-slate-700',
    registered: 'border-blue-200 bg-blue-50 text-blue-800',
    pending: 'border-amber-200 bg-amber-50 text-amber-900',
    'in-progress': 'border-indigo-200 bg-indigo-50 text-indigo-800',
    closed: 'border-slate-300 bg-slate-200 text-slate-800',
    effective: 'border-emerald-200 bg-emerald-50 text-emerald-800',
    inactive: 'border-slate-300 bg-slate-100 text-slate-700',
    revoked: 'border-red-200 bg-red-50 text-red-800',
    superseded: 'border-purple-200 bg-purple-50 text-purple-800',
    cancelled: 'border-red-200 bg-red-50 text-red-800',
};
</script>

<template>
    <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-medium whitespace-nowrap" :class="styles[status] ?? styles.draft">
        {{ label || (Object.hasOwn(styles, status) ? t(`status-${status}`) : t('status-unknown')) }}
    </span>
</template>
