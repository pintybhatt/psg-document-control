<script setup>
import chevronLeftIcon from '@iconify-icons/flowbite/chevron-left-outline';
import { onMounted, ref } from 'vue';
import { useLocalization } from '../../localization/index.js';
import UiButton from './UiButton.vue';

// Short "Back" button. Inside the app shell it moves to the right of the page title row;
// on its own (e.g. in tests) it renders where it is used.
defineProps({ disabled: Boolean, title: { type: String, default: '' } });
const emit = defineEmits(['click']);
const { t } = useLocalization();
const headerTarget = '[data-page-header-actions]';
const inHeader = ref(false);
onMounted(() => { inHeader.value = !!document.querySelector(headerTarget); });
</script>

<template>
    <Teleport v-if="inHeader" :to="headerTarget">
        <UiButton variant="secondary" :icon="chevronLeftIcon" data-page-back :title="title || t('backToRegister')" :aria-label="title || t('backToRegister')" :disabled="disabled" @click="emit('click', $event)">{{ t('uiBack') }}</UiButton>
    </Teleport>
    <UiButton v-else variant="secondary" class="self-start" :icon="chevronLeftIcon" data-page-back :title="title || t('backToRegister')" :aria-label="title || t('backToRegister')" :disabled="disabled" @click="emit('click', $event)">{{ t('uiBack') }}</UiButton>
</template>
