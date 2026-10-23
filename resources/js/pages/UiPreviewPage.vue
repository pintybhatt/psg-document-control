<script setup>
import { computed, nextTick, reactive, ref } from 'vue';
import UiButton from '../components/ui/UiButton.vue';
import TextInput from '../components/ui/TextInput.vue';
import SelectInput from '../components/ui/SelectInput.vue';
import TextareaInput from '../components/ui/TextareaInput.vue';
import CheckboxInput from '../components/ui/CheckboxInput.vue';
import DataTable from '../components/ui/DataTable.vue';
import StatusBadge from '../components/ui/StatusBadge.vue';
import ConfirmDialog from '../components/ui/ConfirmDialog.vue';
import ToastNotifications from '../components/ui/ToastNotifications.vue';
import { useNotifications } from '../components/ui/useNotifications.js';
import { useLocalization } from '../localization/index.js';
import { formatDate, toIsoDate } from '../utilities/dates.js';

const { t } = useLocalization();
const { items, notify, dismiss } = useNotifications();
const statuses = ['draft', 'registered', 'pending', 'in-progress', 'closed', 'effective', 'superseded', 'cancelled'];
const form = reactive({ title: '', category: '', notes: '', acknowledged: false });
const submitted = ref(false);
const sampleForm = ref(null);
const errors = computed(() => ({
    title: submitted.value && !form.title.trim() ? 'uiValidationRequired' : '',
    category: submitted.value && !form.category ? 'uiValidationRequired' : '',
    acknowledged: submitted.value && !form.acknowledged ? 'uiValidationAccept' : '',
}));
const columns = computed(() => [
    { key: 'reference', label: t('uiReference'), type: 'reference', sortable: true },
    { key: 'title', label: t('uiTitle'), sortable: true, width: '40%' },
    { key: 'date', label: t('uiDate'), type: 'date', sortable: true },
    { key: 'status', label: t('uiStatus') },
]);
const rows = computed(() => statuses.map((status, index) => ({
    id: `fictional-${index + 1}`,
    reference: `DEMO-REF-${String(index + 1).padStart(3, '0')}`,
    title: t('uiSampleTitle', { number: index + 1 }),
    date: `2026-10-${String(index + 1).padStart(2, '0')}`,
    status,
})));
const page = ref(1);
const sort = ref({ key: 'reference', direction: 'asc' });
const tableLoading = ref(false);
const tableEmpty = ref(false);
const confirmationOpen = ref(false);
const confirmationDanger = ref(false);

async function validateForm() {
    submitted.value = true;
    await nextTick();

    if (Object.values(errors.value).some(Boolean)) {
        sampleForm.value.querySelector('[aria-invalid="true"]')?.focus();
        return;
    }

    notify({ type: 'success', messageKey: 'uiPreviewSaved' });
}

function openConfirmation(danger = false) {
    confirmationDanger.value = danger;
    confirmationOpen.value = true;
}

function confirmAction() {
    confirmationOpen.value = false;
    notify({ type: 'info', messageKey: 'uiPreviewConfirmed' });
}
</script>

<template>
    <p class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm font-medium text-amber-900">{{ t('uiPreviewWarning') }}</p>

    <section aria-labelledby="preview-buttons" class="rounded-xl border border-slate-200 bg-white p-6">
        <h2 id="preview-buttons" class="mb-5 text-lg font-semibold">{{ t('uiButtons') }}</h2>
        <div class="flex flex-wrap gap-3">
            <UiButton @click="notify({ type: 'success', messageKey: 'uiPreviewNotification' })">{{ t('uiPrimary') }}</UiButton>
            <UiButton variant="secondary" @click="notify({ type: 'info', messageKey: 'uiPreviewNotification' })">{{ t('uiSecondary') }}</UiButton>
            <UiButton variant="danger" @click="openConfirmation(true)">{{ t('uiDanger') }}</UiButton>
            <UiButton disabled>{{ t('uiDisabled') }}</UiButton>
            <UiButton loading>{{ t('uiPrimary') }}</UiButton>
        </div>
    </section>

    <section aria-labelledby="preview-forms" class="rounded-xl border border-slate-200 bg-white p-6">
        <h2 id="preview-forms" class="mb-5 text-lg font-semibold">{{ t('uiForms') }}</h2>
        <form ref="sampleForm" novalidate class="grid gap-5 sm:grid-cols-2" @submit.prevent="validateForm">
            <TextInput v-model="form.title" name="sample-title" :label="t('uiTitle')" :helper="t('uiTitleHelp')" :error-key="errors.title" required />
            <SelectInput v-model="form.category" name="sample-category" :label="t('uiCategory')" :error-key="errors.category" :options="[{ value: '', label: t('uiChoose') }, { value: 'sample', label: t('uiCategoryExample') }]" required />
            <div class="sm:col-span-2"><TextareaInput v-model="form.notes" name="sample-notes" :label="t('uiNotes')" maxlength="1000" /></div>
            <div class="sm:col-span-2"><CheckboxInput v-model="form.acknowledged" name="sample-acknowledged" :label="t('uiAcknowledge')" :error-key="errors.acknowledged" required /></div>
            <div><UiButton type="submit">{{ t('uiValidate') }}</UiButton></div>
        </form>
    </section>

    <section aria-labelledby="preview-statuses" class="rounded-xl border border-slate-200 bg-white p-6">
        <h2 id="preview-statuses" class="mb-5 text-lg font-semibold">{{ t('uiStatuses') }}</h2>
        <div class="flex flex-wrap gap-3"><StatusBadge v-for="status in statuses" :key="status" :status="status" /></div>
    </section>

    <section aria-labelledby="preview-table" class="flex flex-col gap-5">
        <h2 id="preview-table" class="text-lg font-semibold">{{ t('uiTable') }}</h2>
        <div class="flex flex-wrap gap-6">
            <CheckboxInput v-model="tableLoading" :label="t('uiSimulateLoading')" />
            <CheckboxInput v-model="tableEmpty" :label="t('uiSimulateEmpty')" />
        </div>
        <DataTable v-model:page="page" v-model:sort="sort" :columns="columns" :rows="tableEmpty ? [] : rows" :loading="tableLoading" :per-page="3" :caption="t('uiTable')">
            <template #cell-status="{ value }"><StatusBadge :status="value" /></template>
        </DataTable>
    </section>

    <section aria-labelledby="preview-dialogs" class="rounded-xl border border-slate-200 bg-white p-6">
        <h2 id="preview-dialogs" class="mb-5 text-lg font-semibold">{{ t('uiDialogs') }}</h2>
        <div class="flex flex-wrap gap-3">
            <UiButton variant="secondary" @click="openConfirmation()">{{ t('uiOpenDialog') }}</UiButton>
            <UiButton variant="danger" @click="openConfirmation(true)">{{ t('uiOpenDangerDialog') }}</UiButton>
        </div>
    </section>

    <section aria-labelledby="preview-notifications" class="rounded-xl border border-slate-200 bg-white p-6">
        <h2 id="preview-notifications" class="mb-5 text-lg font-semibold">{{ t('uiNotifications') }}</h2>
        <div class="flex flex-wrap gap-3">
            <UiButton v-for="type in ['success', 'warning', 'error', 'info']" :key="type" variant="secondary" @click="notify({ type, messageKey: 'uiPreviewNotification' })">{{ t(`uiToast-${type}`) }}</UiButton>
        </div>
    </section>

    <section aria-labelledby="preview-dates" class="rounded-xl border border-slate-200 bg-white p-6">
        <h2 id="preview-dates" class="mb-5 text-lg font-semibold">{{ t('uiDates') }}</h2>
        <dl class="grid gap-5 sm:grid-cols-3">
            <div><dt class="text-xs text-slate-500">{{ t('uiIsoDate') }}</dt><dd class="mt-1 font-mono text-sm">{{ toIsoDate('2026-10-23') }}</dd></div>
            <div><dt class="text-xs text-slate-500">{{ t('uiDisplayDate') }}</dt><dd class="mt-1 text-sm tabular-nums">{{ formatDate('2026-10-23') }}</dd></div>
            <div><dt class="text-xs text-slate-500">{{ t('uiMissingDate') }}</dt><dd class="mt-1 text-sm">{{ formatDate(null) }}</dd></div>
        </dl>
    </section>

    <ConfirmDialog v-model="confirmationOpen" :danger="confirmationDanger" :title="t(confirmationDanger ? 'uiDangerDialogTitle' : 'uiDialogTitle')" :message="t(confirmationDanger ? 'uiDangerDialogMessage' : 'uiDialogMessage')" @confirm="confirmAction" />
    <ToastNotifications :items="items" @dismiss="dismiss" />
</template>
