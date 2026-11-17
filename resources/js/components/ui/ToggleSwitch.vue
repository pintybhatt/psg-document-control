<script setup>
import FormField from './FormField.vue';
import { formFieldProps } from './formFieldProps.js';

// On/off switch for boolean fields such as active status. It stays a native
// checkbox (role="switch") so keyboard, screen readers and forms work unchanged.
defineOptions({ inheritAttrs: false });
defineProps(formFieldProps);
const model = defineModel({ type: Boolean, default: false });
</script>

<template>
    <FormField :id="id" :label="label" :helper="helper" :error="error" :error-key="errorKey" :required="required" inline v-slot="{ controlAttrs }">
        <span class="relative inline-flex h-6 w-11 shrink-0">
            <input v-model="model" v-bind="{ ...$attrs, ...controlAttrs }" type="checkbox" role="switch" class="peer absolute inset-0 z-10 size-full cursor-pointer opacity-0 disabled:cursor-not-allowed" />
            <span aria-hidden="true" class="h-6 w-11 rounded-full bg-slate-300 transition-colors peer-checked:bg-teal-700 peer-focus-visible:ring-2 peer-focus-visible:ring-teal-600 peer-focus-visible:ring-offset-2 peer-disabled:opacity-50"></span>
            <span aria-hidden="true" class="pointer-events-none absolute left-0.5 top-0.5 size-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5"></span>
        </span>
    </FormField>
</template>
