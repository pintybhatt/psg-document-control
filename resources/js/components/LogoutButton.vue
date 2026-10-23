<script setup>
import { useLocalization } from '../localization/index.js';
import UiButton from './ui/UiButton.vue';

defineProps({ auth: { type: Object, required: true } });
const { language, t } = useLocalization();
</script>

<template>
    <form method="post" :action="auth.logoutUrl">
        <input type="hidden" name="_token" :value="auth.csrfToken" />
        <input type="hidden" name="language" :value="language" />
        <UiButton type="submit" variant="secondary">{{ t('authLogout') }}</UiButton>
    </form>
</template>
