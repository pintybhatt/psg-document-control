<script setup>
import { ref, useAttrs } from 'vue';
import FormField from './FormField.vue';
import { formFieldProps } from './formFieldProps.js';
import { useSelect2 } from '../../utilities/select2.js';

defineOptions({ inheritAttrs: false });
const props = defineProps({ ...formFieldProps, options: { type: Array, required: true } });
const model = defineModel({ type: [String, Number], default: '' });
const attrs = useAttrs();
const select = ref(null);

useSelect2(select, { sources: [() => model.value, () => props.options, () => attrs.disabled, () => props.errorKey, () => props.error] });
</script>

<template>
    <FormField :id="id" :label="label" :helper="helper" :error="error" :error-key="errorKey" :required="required" v-slot="{ controlAttrs }">
        <select ref="select" v-model="model" v-bind="{ ...$attrs, ...controlAttrs }" class="ui-control">
            <option v-for="option in options" :key="option.value" :value="option.value" :disabled="option.disabled">{{ option.label }}</option>
        </select>
    </FormField>
</template>
