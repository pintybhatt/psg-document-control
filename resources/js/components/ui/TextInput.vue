<script setup>
import { Icon } from '@iconify/vue';
import DateInput from './DateInput.vue';
import FormField from './FormField.vue';
import { formFieldProps } from './formFieldProps.js';

defineOptions({ inheritAttrs: false });
defineProps({ ...formFieldProps, type: { type: String, default: 'text' }, icon: { type: Object, default: null } });
const model = defineModel({ type: [String, Number], default: '' });
</script>

<template>
    <DateInput v-if="type === 'date'" v-model="model" v-bind="$attrs" :id="id" :label="label" :helper="helper" :error="error" :error-key="errorKey" :required="required" />
    <FormField v-else :id="id" :label="label" :helper="helper" :error="error" :error-key="errorKey" :required="required" v-slot="{ controlAttrs }">
        <div v-if="icon" class="relative">
            <Icon :icon="icon" aria-hidden="true" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
            <input v-model="model" v-bind="{ ...$attrs, ...controlAttrs }" :type="type" class="ui-control pl-10" />
        </div>
        <input v-else v-model="model" v-bind="{ ...$attrs, ...controlAttrs }" :type="type" class="ui-control" />
    </FormField>
</template>
