<script setup>
import FormField from './FormField.vue';
import { formFieldProps } from './formFieldProps.js';

defineOptions({ inheritAttrs: false });
defineProps({ ...formFieldProps, type: { type: String, default: 'text' } });
const model = defineModel({ type: String, default: '' });
</script>

<template>
    <FormField :id="id" :label="label" :helper="helper" :error="error" :error-key="errorKey" :required="required" v-slot="{ controlAttrs }">
        <input v-model="model" v-bind="{ ...$attrs, ...controlAttrs }" :type="type" class="ui-control" />
    </FormField>
</template>
