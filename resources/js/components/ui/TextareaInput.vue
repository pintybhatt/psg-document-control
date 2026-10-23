<script setup>
import FormField from './FormField.vue';
import { formFieldProps } from './formFieldProps.js';

defineOptions({ inheritAttrs: false });
defineProps(formFieldProps);
const model = defineModel({ type: String, default: '' });
</script>

<template>
    <FormField :id="id" :label="label" :helper="helper" :error="error" :error-key="errorKey" :required="required" v-slot="{ controlAttrs }">
        <textarea v-model="model" rows="3" v-bind="{ ...$attrs, ...controlAttrs }" class="ui-control resize-y" />
    </FormField>
</template>
