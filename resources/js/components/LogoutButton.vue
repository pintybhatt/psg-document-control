<script setup>
import { Icon } from '@iconify/vue';
import signOutIcon from '@iconify-icons/flowbite/arrow-left-to-bracket-outline';
import { useLocalization } from '../localization/index.js';
import UiButton from './ui/UiButton.vue';

defineProps({ auth: { type: Object, required: true }, menu: Boolean });
const { language, t } = useLocalization();
</script>

<template>
    <form method="post" :action="auth.logoutUrl">
        <input type="hidden" name="_token" :value="auth.csrfToken" />
        <input type="hidden" name="language" :value="language" />
        <button v-if="menu" type="submit" :title="t('authLogout')" class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left text-sm font-medium text-red-600 hover:bg-red-50">
            <Icon :icon="signOutIcon" aria-hidden="true" class="size-5 shrink-0" />{{ t('authLogout') }}
        </button>
        <UiButton v-else type="submit" variant="secondary">{{ t('authLogout') }}</UiButton>
    </form>
</template>
