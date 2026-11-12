<script setup>
import { nextTick, onMounted, reactive, ref } from 'vue';
import { Icon } from '@iconify/vue';
import Swal from 'sweetalert2';
import lockKeyholeIcon from '@iconify-icons/flowbite/lock-outline';
import logInIcon from '@iconify-icons/flowbite/arrow-right-to-bracket-outline';
import mailIcon from '@iconify-icons/flowbite/envelope-outline';
import shieldCheckIcon from '@iconify-icons/flowbite/shield-check-outline';
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
const serverAlertIcons = { authInvalidCredentials: 'error', authThrottled: 'warning' };

onMounted(async () => {
    const errorKey = Object.values(errors).find(key => Object.hasOwn(serverAlertIcons, key));
    if (!errorKey) {
        return;
    }

    for (const field of Object.keys(errors)) {
        if (errors[field] === errorKey) {
            errors[field] = '';
        }
    }

    await Swal.fire({
        icon: serverAlertIcons[errorKey],
        title: t('authSignInFailed'),
        text: t(errorKey),
        confirmButtonText: t('authAlertConfirm'),
        buttonsStyling: false,
        returnFocus: false,
        customClass: {
            popup: 'rounded-3xl! px-2 pb-6',
            title: 'text-xl! font-semibold text-slate-900!',
            htmlContainer: 'text-sm! leading-6 text-slate-500!',
            confirmButton: 'inline-flex min-h-11 min-w-32 items-center justify-center rounded-xl border border-teal-700 bg-teal-700 px-6 py-2 text-sm font-semibold text-white shadow-md shadow-teal-700/20 hover:bg-teal-800',
        },
    });
    form.value?.querySelector('input[name="password"]')?.focus();
});

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
        <section class="relative isolate flex flex-col justify-between gap-8 overflow-hidden bg-linear-to-br from-slate-950 via-[#06213a] to-teal-950 px-6 py-8 text-white lg:w-5/12 lg:p-12">
            <div aria-hidden="true" class="pointer-events-none absolute inset-0 -z-10">
                <div class="absolute -top-32 -left-24 size-96 rounded-full bg-teal-500/25 blur-3xl"></div>
                <div class="absolute top-1/3 -right-32 size-[28rem] rounded-full bg-sky-500/20 blur-3xl"></div>
                <div class="absolute -bottom-40 left-1/4 size-96 rounded-full bg-emerald-400/15 blur-3xl"></div>
                <div class="absolute inset-0 bg-[radial-gradient(circle,rgb(255_255_255/0.06)_1px,transparent_1px)] bg-size-[22px_22px] mask-[linear-gradient(to_bottom,black,transparent_85%)]"></div>
            </div>
            <div class="flex w-fit items-center gap-3 rounded-2xl border border-white/10 bg-white/5 py-2 pr-4 pl-2 shadow-lg shadow-black/10 backdrop-blur-md">
                <img src="/logo/psg.png" alt="PSG" class="size-10 rounded-xl object-contain" />
                <span class="text-sm font-semibold">Phonesack Group</span>
            </div>
            <div class="@container min-w-0 rounded-3xl border border-white/10 bg-white/[0.06] p-6 shadow-2xl shadow-black/20 ring-1 ring-white/5 backdrop-blur-xl sm:p-8">
                <p class="mb-3 inline-flex items-center gap-2 rounded-full border border-teal-300/20 bg-teal-300/10 px-3 py-1 text-xs font-semibold tracking-wider text-teal-200">DCMS</p>
                <h1 class="text-[clamp(0.875rem,5.5cqi,1.875rem)] leading-relaxed font-semibold" :class="{ 'whitespace-nowrap': language === 'en' }">{{ t('appDescription') }}</h1>
                <p class="mt-4 max-w-lg text-sm leading-7 text-slate-300">{{ t('authWelcome') }}</p>
            </div>
            <p class="hidden text-xs text-slate-400 lg:block">{{ t('footer') }}</p>
        </section>
        <section class="flex flex-1 flex-col gap-8 bg-slate-50 bg-[radial-gradient(circle,var(--color-slate-200)_1px,transparent_1px)] bg-size-[24px_24px] p-6 sm:p-10">
            <div class="flex justify-end"><LanguageSwitcher /></div>
            <div class="relative my-auto w-full max-w-md self-center overflow-hidden rounded-3xl border border-slate-200 bg-white px-6 pt-10 pb-8 shadow-[0_1px_2px_rgb(15_23_42/0.04),0_24px_48px_-16px_rgb(15_23_42/0.18)] sm:px-10">
                <div aria-hidden="true" class="absolute inset-x-0 top-0 h-1.5 bg-gradient-to-r from-teal-500 via-teal-600 to-sky-500"></div>
                <div class="flex flex-col items-center text-center">
                    <span class="flex size-14 items-center justify-center rounded-2xl bg-teal-50 text-teal-700 ring-8 ring-teal-50/50">
                        <Icon :icon="shieldCheckIcon" aria-hidden="true" class="size-7" />
                    </span>
                    <h2 class="mt-6 text-2xl font-semibold text-slate-900">{{ t('login') }}</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-500">{{ t('authInstructions') }}</p>
                </div>
                <form ref="form" method="post" :action="auth.loginUrl" novalidate class="mt-8 flex flex-col gap-5" @submit="validate">
                    <input type="hidden" name="_token" :value="auth.csrfToken" />
                    <input type="hidden" name="language" :value="language" />
                    <TextInput v-model="email" name="email" type="email" autocomplete="username" autocapitalize="none" spellcheck="false" :label="t('authEmail')" :placeholder="t('authEmailPlaceholder')" :icon="mailIcon" class="min-h-11 rounded-xl" :error-key="errors.email" required @update:model-value="errors.email = ''" />
                    <TextInput v-model="password" name="password" type="password" autocomplete="current-password" :label="t('authPassword')" :placeholder="t('authPasswordPlaceholder')" :icon="lockKeyholeIcon" class="min-h-11 rounded-xl" :error-key="errors.password" required @update:model-value="errors.password = ''" />
                    <p v-if="errors.language" role="alert" class="text-sm text-red-700">{{ t(errors.language) }}</p>
                    <UiButton type="submit" :loading="submitting" class="mt-1 min-h-11 w-full rounded-xl shadow-md shadow-teal-700/20">
                        <Icon v-if="!submitting" :icon="logInIcon" aria-hidden="true" class="size-4" />
                        {{ t('authSignIn') }}
                    </UiButton>
                </form>
                <p class="mt-8 flex items-center justify-center gap-2 border-t border-slate-100 pt-6 text-center text-xs text-slate-500">
                    <Icon :icon="lockKeyholeIcon" aria-hidden="true" class="size-3.5 shrink-0" />
                    {{ t('authAccessHelp') }}
                </p>
            </div>
        </section>
    </main>
</template>
