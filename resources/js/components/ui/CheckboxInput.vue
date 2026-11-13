<script setup>
import FormField from './FormField.vue';
import { formFieldProps } from './formFieldProps.js';

defineOptions({ inheritAttrs: false });
defineProps(formFieldProps);
const model = defineModel({ type: Boolean, default: false });
</script>

<template>
    <FormField :id="id" :label="label" :helper="helper" :error="error" :error-key="errorKey" :required="required" inline v-slot="{ controlAttrs }">
        <input v-model="model" v-bind="{ ...$attrs, ...controlAttrs }" type="checkbox" class="mt-0.5 size-4 shrink-0 accent-teal-700 disabled:cursor-not-allowed disabled:opacity-50" />
    </FormField>
</template>
