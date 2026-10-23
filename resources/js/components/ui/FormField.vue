<script setup>
import { computed, useId } from 'vue';
import { useLocalization } from '../../localization/index.js';
import { formFieldProps } from './formFieldProps.js';

const props = defineProps({ ...formFieldProps, inline: Boolean });
const { t } = useLocalization();
const generatedId = useId();
const controlId = computed(() => props.id ?? `field-${generatedId}`);
const errorMessage = computed(() => props.errorKey ? t(props.errorKey) : props.error);
const controlAttrs = computed(() => ({
    id: controlId.value,
    required: props.required,
    'aria-invalid': errorMessage.value ? 'true' : undefined,
    'aria-describedby': [props.helper && `${controlId.value}-help`, errorMessage.value && `${controlId.value}-error`].filter(Boolean).join(' ') || undefined,
}));
</script>

<template>
    <div class="flex flex-col gap-2">
        <div :class="inline ? 'flex items-start gap-3' : 'flex flex-col gap-2'">
            <label :for="controlId" class="text-sm font-medium text-slate-700" :class="inline ? 'order-2' : ''">
                {{ label }} <span v-if="required" class="text-red-700" aria-hidden="true">*</span>
                <span v-if="required" class="sr-only">{{ t('uiRequired') }}</span>
            </label>
            <slot :control-attrs="controlAttrs" />
        </div>
        <p v-if="helper" :id="`${controlId}-help`" class="text-xs leading-6 text-slate-500">{{ helper }}</p>
        <p v-if="errorMessage" :id="`${controlId}-error`" role="alert" class="text-sm text-red-700">{{ errorMessage }}</p>
    </div>
</template>
