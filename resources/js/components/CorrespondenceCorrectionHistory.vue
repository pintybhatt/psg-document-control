<script setup>
import { computed } from 'vue';
import { useLocalization } from '../localization/index.js';
import { formatDate } from '../utilities/dates.js';
const props = defineProps({ item: { type: Object, required: true }, kind: { type: String, required: true } });
const { t } = useLocalization();
const changes = computed(() => {
    const before = props.item.before.details ?? {};
    const after = props.item.after.details ?? {};
    const result = Object.keys(after).filter(field => field !== 'recipients').map(field => ({ key: field, label: t(field === 'priority' ? `${props.kind}Priority` : field === 'channel' ? 'incomingChannel' : `${props.kind}-${field}`), before: before[field], after: after[field], field }));
    for (const [id, fields] of Object.entries(after.recipients ?? {})) {
        for (const field of Object.keys(fields)) { result.push({ key: `${id}-${field}`, label: `${t('outgoingRecipient')} · ${t(`outgoingRecipient-${field}`)}`, before: before.recipients?.[id]?.[field], after: fields[field], field }); }
    }
    return result;
});
function display(field, value) {
    if (value == null || value === '') { return '—'; }
    if (field.endsWith('_date')) { return formatDate(String(value).slice(0, 10)); }
    if (field === 'priority') { return t(`incomingPriority-${value}`); }
    if (field === 'channel') { return t(`incomingChannel-${value}`); }
    return value;
}
</script>

<template>
    <dl class="text-sm text-slate-600"><div v-for="change in changes" :key="change.key" class="whitespace-pre-wrap wrap-anywhere"><dt class="inline font-medium">{{ change.label }}: </dt><dd class="inline">{{ display(change.field, change.before) }} → {{ display(change.field, change.after) }}</dd></div></dl>
</template>
