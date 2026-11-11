<script setup>
import { computed, reactive, ref } from 'vue';
import { Icon } from '@iconify/vue';
import lockIcon from '@iconify-icons/flowbite/lock-solid';
import checkIcon from '@iconify-icons/flowbite/check-circle-solid';
import circleIcon from '@iconify-icons/flowbite/close-circle-outline';
import TextInput from './ui/TextInput.vue';
import CheckboxInput from './ui/CheckboxInput.vue';
import UiButton from './ui/UiButton.vue';
import { showSuccess } from '../utilities/alerts.js';
import { createJsonClient } from '../utilities/jsonClient.js';
import { useLocalization } from '../localization/index.js';

const props = defineProps({
    endpoint: { type: String, required: true },
    csrfToken: { type: String, required: true },
});
const { t } = useLocalization();
const request = createJsonClient(props.csrfToken);
const form = reactive({ current_password: '', password: '', password_confirmation: '' });
const fields = ref({});
const error = ref('');
const saving = ref(false);
const reveal = ref(false);
const inputType = computed(() => reveal.value ? 'text' : 'password');

const strength = computed(() => {
    const value = form.password;
    if (!value) { return null; }
    const score = [value.length >= 8, value.length >= 12, /[a-z]/.test(value) && /[A-Z]/.test(value), /\d/.test(value), /[^A-Za-z0-9]/.test(value)].filter(Boolean).length;
    const level = value.length < 8 || score <= 2 ? 'weak' : score === 3 ? 'fair' : score === 4 ? 'good' : 'strong';
    return { level, bars: { weak: 1, fair: 2, good: 3, strong: 4 }[level] };
});
const barTone = { weak: 'bg-red-500', fair: 'bg-amber-500', good: 'bg-sky-500', strong: 'bg-emerald-500' };
const textTone = { weak: 'text-red-700', fair: 'text-amber-700', good: 'text-sky-700', strong: 'text-emerald-700' };

const rules = computed(() => [
    { key: 'length', met: form.password.length >= 8 },
    { key: 'different', met: !!form.password && form.password !== form.current_password },
    { key: 'match', met: !!form.password && form.password === form.password_confirmation },
]);
const ready = computed(() => !!form.current_password && rules.value.every(rule => rule.met));

const fieldKey = name => fields.value[name]?.[0] || '';

async function submit() {
    error.value = '';
    fields.value = {};
    if (form.password !== form.password_confirmation) {
        fields.value = { password: ['profilePasswordMismatch'] };
        return;
    }
    saving.value = true;
    try {
        await request(props.endpoint, { method: 'PUT', body: { ...form } });
        Object.assign(form, { current_password: '', password: '', password_confirmation: '' });
        reveal.value = false;
        showSuccess(t('profilePasswordUpdated'), t('profilePasswordUpdatedText'));
    } catch (failure) {
        fields.value = failure.fields ?? {};
        if (!Object.keys(fields.value).length) {
            error.value = failure.message === 'rbacSessionExpired' ? 'rbacSessionExpired' : 'profilePasswordFailed';
        }
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <section data-profile-security class="overflow-hidden rounded-3xl border border-slate-200/80 bg-white shadow-sm shadow-slate-900/5">
        <div class="grid lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
            <header class="flex flex-col gap-4 border-b border-slate-100 bg-linear-to-br from-slate-50 to-teal-50/40 p-6 sm:px-8 lg:border-r lg:border-b-0">
                <span aria-hidden="true" class="inline-flex size-11 items-center justify-center rounded-xl bg-linear-to-br from-slate-800 to-teal-800 text-white shadow-md shadow-slate-900/20"><Icon :icon="lockIcon" class="size-5" /></span>
                <div>
                    <h2 class="text-base font-semibold text-slate-900">{{ t('profileSecurityTitle') }}</h2>
                    <p class="mt-1 text-sm leading-6 text-slate-500">{{ t('profileSecurityHelp') }}</p>
                </div>
                <ul class="flex flex-col gap-2 text-sm" data-password-rules>
                    <li v-for="rule in rules" :key="rule.key" :data-password-rule="rule.key" :data-met="rule.met" class="flex items-center gap-2" :class="rule.met ? 'text-emerald-700' : 'text-slate-500'">
                        <Icon :icon="rule.met ? checkIcon : circleIcon" aria-hidden="true" class="size-4 shrink-0" :class="rule.met ? 'text-emerald-500' : 'text-slate-300'" />
                        {{ t(`profilePasswordRule${rule.key[0].toUpperCase()}${rule.key.slice(1)}`) }}
                    </li>
                </ul>
            </header>

            <form data-password-form class="flex flex-col gap-5 p-6 sm:px-8" novalidate @submit.prevent="submit">
                <!-- Hidden username lets password managers pair the new password with this account. -->
                <input type="text" autocomplete="username" class="hidden" tabindex="-1" aria-hidden="true" />
                <TextInput v-model="form.current_password" name="current_password" :type="inputType" autocomplete="current-password" :label="t('profilePasswordCurrent')" required maxlength="72" :error-key="fieldKey('current_password')" />
                <div class="grid gap-5 md:grid-cols-2">
                    <div class="flex flex-col gap-2">
                        <TextInput v-model="form.password" name="password" :type="inputType" autocomplete="new-password" :label="t('profilePasswordNew')" required maxlength="72" :error-key="fieldKey('password')" />
                        <div v-if="strength" data-password-strength :data-level="strength.level" class="flex items-center gap-3">
                            <div aria-hidden="true" class="grid flex-1 grid-cols-4 gap-1">
                                <span v-for="bar in 4" :key="bar" class="h-1.5 rounded-full transition-colors" :class="bar <= strength.bars ? barTone[strength.level] : 'bg-slate-200'"></span>
                            </div>
                            <span class="shrink-0 text-xs font-semibold" :class="textTone[strength.level]">{{ t('profilePasswordStrength', { level: t(`profilePasswordStrength-${strength.level}`) }) }}</span>
                        </div>
                    </div>
                    <TextInput v-model="form.password_confirmation" name="password_confirmation" :type="inputType" autocomplete="new-password" :label="t('profilePasswordConfirm')" required maxlength="72" :error-key="fieldKey('password_confirmation')" />
                </div>
                <p v-if="error" role="alert" data-password-error class="rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-800">{{ t(error) }}</p>
                <div class="flex flex-col-reverse gap-4 border-t border-slate-100 pt-5 sm:flex-row sm:items-center sm:justify-between">
                    <CheckboxInput v-model="reveal" :label="t('profilePasswordShow')" />
                    <UiButton type="submit" data-password-submit :loading="saving" :disabled="!ready">{{ t('profilePasswordSave') }}</UiButton>
                </div>
            </form>
        </div>
    </section>
</template>
