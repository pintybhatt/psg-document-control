<script setup>
import { ref, watch } from 'vue';
import { formatDate, parseDisplayDate } from '../../utilities/dates.js';
import { useLocalization } from '../../localization/index.js';
import FormField from './FormField.vue';
import { formFieldProps } from './formFieldProps.js';

defineOptions({ inheritAttrs: false });
defineProps({ ...formFieldProps, type: { type: String, default: 'text' } });
const model = defineModel({ type: [String, Number], default: '' });
const { t } = useLocalization();
const displayedDate = ref('');
const invalidDate = ref(false);
watch(model, value => { if (!value || /^\d{4}-\d{2}-\d{2}$/.test(String(value))) { displayedDate.value = value ? formatDate(value, '') : ''; } }, { immediate: true });
function updateDate(event) {
    const value = event.target.value;
    displayedDate.value = value;
    const parsed = parseDisplayDate(value);
    event.target.setCustomValidity(value && !parsed ? t('dateInvalid') : '');
    if (!value || parsed) { invalidDate.value = false; }
    model.value = parsed ?? (value ? value : '');
}
</script>

<template>
    <FormField :id="id" :label="label" :helper="helper" :error="error" :error-key="errorKey || (invalidDate ? 'dateInvalid' : '')" :required="required" v-slot="{ controlAttrs }">
        <input v-if="type === 'date'" :value="displayedDate" v-bind="{ ...$attrs, ...controlAttrs }" type="text" inputmode="numeric" placeholder="dd/mm/yyyy" pattern="[0-9]{2}/[0-9]{2}/[0-9]{4}" class="ui-control tabular-nums" @input="updateDate" @blur="invalidDate = !!displayedDate && !parseDisplayDate(displayedDate)" />
        <input v-else v-model="model" v-bind="{ ...$attrs, ...controlAttrs }" :type="type" class="ui-control" />
    </FormField>
</template>
