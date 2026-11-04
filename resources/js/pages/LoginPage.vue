<script setup>
import { nextTick, reactive, ref } from 'vue';
import LanguageSwitcher from '../components/LanguageSwitcher.vue';
import TextInput from '../components/ui/TextInput.vue';
import UiButton from '../components/ui/UiButton.vue';
import { useLocalization } from '../localization/index.js';

const props = defineProps({ auth: { type: Object, required: true } });
const { language, t } = useLocalization();
const email = ref(props.auth.email ?? '');
const password = ref('');
const errors = reactive({ ...props.auth.errors });
const submitting = ref(false);
const form = ref(null);

async function validate(event) {
    if (submitting.value) {
        event.preventDefault();
        return;
    }

    errors.email = !email.value.trim() ? 'authEmailRequired'
        : (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim()) ? 'authEmailInvalid' : '');
    errors.password = password.value ? '' : 'authPasswordRequired';

    if (errors.email || errors.password) {
        event.preventDefault();
        await nextTick();
        form.value.querySelector('[aria-invalid="true"]')?.focus();
        return;
    }

    submitting.value = true;
}
</script>

<template>
    <main class="flex min-h-dvh flex-col bg-slate-50 text-slate-800 lg:flex-row">
        <section class="@container flex flex-col justify-between gap-8 bg-slate-950 px-6 py-8 text-white lg:w-5/12 lg:p-12">
            <div class="flex items-center gap-3">
                <span class="flex size-11 items-center justify-center rounded-xl bg-teal-400 text-sm font-bold text-slate-950">PSG</span>
                <span class="text-sm font-semibold">Phonesack Group</span>
            </div>
            <div class="min-w-0">
                <p class="mb-3 text-sm font-semibold text-teal-300">DCMS</p>
                <h1 class="text-[clamp(0.875rem,5cqi,1.875rem)] leading-relaxed font-semibold" :class="{ 'whitespace-nowrap': language === 'en' }">{{ t('appDescription') }}</h1>
                <p class="mt-4 max-w-lg text-sm leading-7 text-slate-300">{{ t('authWelcome') }}</p>
            </div>
            <p class="hidden text-xs text-slate-400 lg:block">{{ t('footer') }}</p>
        </section>
        <section class="flex flex-1 flex-col gap-8 p-6 sm:p-10">
            <div class="flex justify-end"><LanguageSwitcher /></div>
            <div class="my-auto w-full max-w-md self-center rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
                <h2 class="text-2xl font-semibold">{{ t('login') }}</h2>
                <p class="mt-3 text-sm leading-7 text-slate-500">{{ t('authInstructions') }}</p>
                <form ref="form" method="post" :action="auth.loginUrl" novalidate class="mt-6 flex flex-col gap-5" @submit="validate">
                    <input type="hidden" name="_token" :value="auth.csrfToken" />
                    <input type="hidden" name="language" :value="language" />
                    <TextInput v-model="email" name="email" type="email" autocomplete="username" autocapitalize="none" spellcheck="false" :label="t('authEmail')" :error-key="errors.email" required @update:model-value="errors.email = ''" />
                    <TextInput v-model="password" name="password" type="password" autocomplete="current-password" :label="t('authPassword')" :error-key="errors.password" required @update:model-value="errors.password = ''" />
                    <p v-if="errors.language" role="alert" class="text-sm text-red-700">{{ t(errors.language) }}</p>
                    <UiButton type="submit" :loading="submitting" class="w-full">{{ t('authSignIn') }}</UiButton>
                </form>
            </div>
        </section>
    </main>
</template>
