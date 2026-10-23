<script setup>
import FormField from './FormField.vue';
import { formFieldProps } from './formFieldProps.js';

defineOptions({ inheritAttrs: false });
defineProps({ ...formFieldProps, options: { type: Array, required: true } });
const model = defineModel({ type: [String, Number], default: '' });
</script>

<template>
    <FormField :id="id" :label="label" :helper="helper" :error="error" :error-key="errorKey" :required="required" v-slot="{ controlAttrs }">
        <select v-model="model" v-bind="{ ...$attrs, ...controlAttrs }" class="ui-control">
            <option v-for="option in options" :key="option.value" :value="option.value" :disabled="option.disabled">{{ option.label }}</option>
        </select>
    </FormField>
</template>
