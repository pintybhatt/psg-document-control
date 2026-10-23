<script setup>
import { computed, watch } from 'vue';
import { useLocalization } from '../../localization/index.js';
import { formatDate } from '../../utilities/dates.js';
import UiButton from './UiButton.vue';

const props = defineProps({
    columns: { type: Array, required: true },
    rows: { type: Array, default: () => [] },
    caption: { type: String, required: true },
    rowKey: { type: String, default: 'id' },
    perPage: { type: Number, default: 10, validator: value => Number.isInteger(value) && value > 0 },
    loading: Boolean,
    serverSide: Boolean,
    total: { type: Number, default: null },
});
const page = defineModel('page', { type: Number, default: 1 });
const sort = defineModel('sort', { type: Object, default: () => ({ key: null, direction: 'asc' }) });
const emit = defineEmits(['change']);
const { t, language } = useLocalization();
const pageSize = computed(() => Math.max(1, Math.floor(props.perPage) || 10));
const rowCount = computed(() => props.serverSide ? Math.max(0, props.total ?? props.rows.length) : props.rows.length);
const pageCount = computed(() => Math.max(1, Math.ceil(rowCount.value / pageSize.value)));
const currentPage = computed(() => Math.min(pageCount.value, Math.max(1, Math.floor(page.value) || 1)));
const collator = computed(() => new Intl.Collator(language.value, { numeric: true, sensitivity: 'base' }));
const sortedRows = computed(() => {
    const column = props.columns.find(column => column.key === sort.value.key && column.sortable);

    if (props.serverSide || !column) {
        return props.rows;
    }

    return [...props.rows].sort((first, second) => {
        const a = first[column.key];
        const b = second[column.key];

        if (a === null || a === undefined || a === '') {
            return b === null || b === undefined || b === '' ? 0 : 1;
        }
        if (b === null || b === undefined || b === '') {
            return -1;
        }

        const comparison = typeof a === 'number' && typeof b === 'number' ? a - b : collator.value.compare(String(a), String(b));

        return sort.value.direction === 'desc' ? -comparison : comparison;
    });
});
const visibleRows = computed(() => props.serverSide ? sortedRows.value : sortedRows.value.slice((currentPage.value - 1) * pageSize.value, currentPage.value * pageSize.value));

function notifyChange(requestedPage = currentPage.value, requestedSort = sort.value) {
    emit('change', { page: requestedPage, perPage: pageSize.value, sort: { ...requestedSort } });
}

function changePage(value) {
    if (props.loading || value < 1 || value > pageCount.value) {
        return;
    }
    page.value = value;
    notifyChange(value);
}

function sortBy(column) {
    if (props.loading || !column.sortable) {
        return;
    }
    const requestedSort = { key: column.key, direction: sort.value.key === column.key && sort.value.direction === 'asc' ? 'desc' : 'asc' };
    sort.value = requestedSort;
    page.value = 1;
    notifyChange(1, requestedSort);
}

watch([pageCount, () => props.loading, pageSize], () => {
    if (!props.loading && page.value !== currentPage.value) {
        page.value = currentPage.value;
        notifyChange();
    }
});

function columnClasses(column) {
    return {
        'min-w-52 font-mono whitespace-nowrap': column.type === 'reference',
        'min-w-32 whitespace-nowrap tabular-nums': column.type === 'date',
        'min-w-40': !['reference', 'date'].includes(column.type),
    };
}
</script>

<template>
    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <div role="region" :aria-label="caption" tabindex="0" class="overflow-x-auto">
            <table class="w-full text-left text-sm" :aria-busy="loading">
                <caption class="sr-only">{{ caption }}. {{ t('uiTableHelp') }}</caption>
                <thead class="border-b border-slate-200 bg-slate-100 text-xs text-slate-600">
                    <tr>
                        <th v-for="column in columns" :key="column.key" scope="col" :aria-sort="sort.key === column.key && column.sortable ? (sort.direction === 'desc' ? 'descending' : 'ascending') : undefined" :style="{ width: column.width }" class="px-4 py-3 font-semibold" :class="columnClasses(column)">
                            <button v-if="column.sortable" type="button" :disabled="loading" :aria-label="t(sort.key === column.key && sort.direction === 'asc' ? 'uiSortDescending' : 'uiSortAscending', { column: column.label })" class="flex min-h-8 w-full items-center gap-2 rounded text-left disabled:opacity-50" @click="sortBy(column)">
                                {{ column.label }} <span aria-hidden="true">{{ sort.key === column.key ? (sort.direction === 'desc' ? '↓' : '↑') : '↕' }}</span>
                            </button>
                            <span v-else>{{ column.label }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-if="loading || !visibleRows.length">
                        <td :colspan="columns.length" class="px-4 py-12 text-center text-slate-500">{{ t(loading ? 'uiLoading' : 'uiEmpty') }}</td>
                    </tr>
                    <tr v-for="row in loading ? [] : visibleRows" :key="row[rowKey]" class="hover:bg-slate-50">
                        <td v-for="column in columns" :key="column.key" class="px-4 py-3 leading-6" :class="columnClasses(column)">
                            <slot :name="`cell-${column.key}`" :row="row" :value="row[column.key]">{{ column.type === 'date' ? formatDate(row[column.key]) : (row[column.key] ?? '—') }}</slot>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-4 py-3">
            <p role="status" class="text-xs text-slate-600">{{ loading ? t('uiLoading') : t('uiPageSummary', { page: currentPage, pages: pageCount, total: rowCount }) }}</p>
            <nav :aria-label="t('uiPagination')" class="flex gap-2">
                <UiButton variant="secondary" :disabled="loading || currentPage <= 1" @click="changePage(currentPage - 1)">{{ t('uiPrevious') }}</UiButton>
                <UiButton variant="secondary" :disabled="loading || currentPage >= pageCount" @click="changePage(currentPage + 1)">{{ t('uiNext') }}</UiButton>
            </nav>
        </div>
    </div>
</template>
