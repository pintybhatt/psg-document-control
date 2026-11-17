<script setup>
import { Icon } from '@iconify/vue';
import excelIcon from '@iconify-icons/flowbite/file-csv-outline';
import pdfIcon from '@iconify-icons/flowbite/file-pdf-outline';
import { useLocalization } from '../../localization/index.js';

// Report export pair: Excel download (green) and PDF preview (primary teal).
defineProps({ excelHref: { type: String, required: true }, disabled: Boolean });
const emit = defineEmits(['preview']);
const { t } = useLocalization();
const base = 'inline-flex min-h-10 items-center gap-2 rounded-xl px-4 py-2 text-sm font-semibold text-white shadow-md transition-colors';
</script>

<template>
    <div class="flex flex-wrap gap-2" data-export-actions>
        <a :href="excelHref" data-export-excel :title="t('reportDownloadXlsx')" :class="[base, 'bg-emerald-600 shadow-emerald-700/20 hover:bg-emerald-700']">
            <Icon :icon="excelIcon" aria-hidden="true" class="size-5" />{{ t('reportDownloadXlsx') }}
        </a>
        <button type="button" data-export-pdf :title="t('reportPreviewPdf')" :disabled="disabled" :class="[base, 'bg-teal-700 shadow-teal-700/20 hover:bg-teal-800 disabled:cursor-not-allowed disabled:opacity-50']" @click="emit('preview')">
            <Icon :icon="pdfIcon" aria-hidden="true" class="size-5" />{{ t('reportPreviewPdf') }}
        </button>
    </div>
</template>
