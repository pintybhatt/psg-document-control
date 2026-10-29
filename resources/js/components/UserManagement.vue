<script setup>
import { onMounted, reactive, ref } from 'vue';
import UiButton from './ui/UiButton.vue';
import TextInput from './ui/TextInput.vue';
import SelectInput from './ui/SelectInput.vue';
import CheckboxInput from './ui/CheckboxInput.vue';
import { createJsonClient } from '../utilities/jsonClient.js';
import { useLocalization } from '../localization/index.js';

const props = defineProps({ endpoint: { type: String, required: true }, csrfToken: { type: String, required: true }, currentUserId: { type: Number, required: true } });
const { t } = useLocalization();
const request = createJsonClient(props.csrfToken);
const users = ref([]);
const search = ref('');
const page = ref(1);
const lastPage = ref(1);
const loading = ref(false);
const saving = ref(false);
const editing = ref(null);
const error = ref('');
const saved = ref(false);
const fields = ref({});
const form = reactive({ name: '', email: '', preferred_language: 'en', password: '', password_confirmation: '', active: true });

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const response = await request(`${props.endpoint}?${new URLSearchParams({ q: search.value, page: page.value })}`);
        users.value = response.data;
        lastPage.value = response.last_page;
    } catch (exception) { error.value = t(exception.message || 'userRequestFailed'); }
    finally { loading.value = false; }
}

function open(user = null) {
    editing.value = user ?? { id: null };
    Object.assign(form, { name: user?.name ?? '', email: user?.email ?? '', preferred_language: user?.preferred_language ?? 'en', password: '', password_confirmation: '', active: user?.active ?? true });
    fields.value = {};
    saved.value = false;
    error.value = '';
}

async function save() {
    if (saving.value) { return; }
    saving.value = true;
    error.value = '';
    fields.value = {};
    const updating = editing.value.id !== null;
    const body = { name: form.name, email: form.email, preferred_language: form.preferred_language };
    if (updating && editing.value.id !== props.currentUserId) { body.active = form.active; }
    if (!updating || form.password) { body.password = form.password; body.password_confirmation = form.password_confirmation; }
    try {
        await request(updating ? `${props.endpoint}/${editing.value.id}` : props.endpoint, { method: updating ? 'PATCH' : 'POST', body });
        editing.value = null;
        saved.value = true;
        await load();
    } catch (exception) {
        fields.value = exception.fields ?? {};
        error.value = t(exception.message || 'userRequestFailed');
    } finally { saving.value = false; }
}

async function changePage(direction) {
    page.value += direction;
    await load();
}

onMounted(load);
</script>

<template>
    <section class="flex flex-col gap-4" aria-labelledby="users-heading">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h3 id="users-heading" class="font-semibold">{{ t('userManagementTitle') }}</h3>
            <UiButton @click="open()">{{ t('userCreate') }}</UiButton>
        </div>
        <form class="flex flex-wrap items-end gap-3" @submit.prevent="page = 1; load()">
            <TextInput v-model="search" :label="t('userSearch')" />
            <UiButton type="submit" variant="secondary" :loading="loading">{{ t('userFind') }}</UiButton>
        </form>
        <p v-if="error" role="alert" class="text-sm text-red-700">{{ error }}</p>
        <p v-if="saved" role="status" class="text-sm text-teal-700">{{ t('userSaved') }}</p>
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50"><tr><th class="p-3">{{ t('userName') }}</th><th class="p-3">{{ t('userEmail') }}</th><th class="p-3">{{ t('uiStatus') }}</th><th class="p-3">{{ t('rbacActions') }}</th></tr></thead>
                <tbody><tr v-for="user in users" :key="user.id" class="border-t border-slate-200"><td class="p-3">{{ user.name }}</td><td class="p-3">{{ user.email }}</td><td class="p-3">{{ t(user.active ? 'rbacActive' : 'rbacInactive') }}</td><td class="p-3"><UiButton variant="secondary" @click="open(user)">{{ t('rbacEdit') }}</UiButton></td></tr></tbody>
            </table>
            <p v-if="!loading && !users.length" class="p-4 text-sm text-slate-500">{{ t('userNone') }}</p>
        </div>
        <div class="flex items-center gap-3"><UiButton variant="secondary" :disabled="page <= 1 || loading" @click="changePage(-1)">{{ t('userPrevious') }}</UiButton><span class="text-sm">{{ page }} / {{ lastPage }}</span><UiButton variant="secondary" :disabled="page >= lastPage || loading" @click="changePage(1)">{{ t('userNext') }}</UiButton></div>
        <form v-if="editing" class="rounded-xl border border-slate-200 bg-white p-5" @submit.prevent="save">
            <h4 class="mb-4 font-semibold">{{ t(editing.id === null ? 'userCreate' : 'userEdit') }}</h4>
            <div class="grid gap-4 sm:grid-cols-2">
                <TextInput v-model="form.name" :label="t('userName')" required maxlength="255" :error="fields.name?.[0]" />
                <TextInput v-model="form.email" :label="t('userEmail')" type="email" required maxlength="255" :error="fields.email?.[0]" />
                <SelectInput v-model="form.preferred_language" :label="t('userLanguage')" :options="[{ value: 'en', label: 'English' }, { value: 'lo', label: 'ລາວ' }]" />
                <div v-if="editing.id !== null && editing.id !== currentUserId"><CheckboxInput v-model="form.active" :label="t('userActive')" /></div>
                <TextInput v-model="form.password" type="password" autocomplete="new-password" :label="t(editing.id === null ? 'userInitialPassword' : 'userResetPassword')" :required="editing.id === null" :error="fields.password?.[0]" />
                <TextInput v-model="form.password_confirmation" type="password" autocomplete="new-password" :label="t('userConfirmPassword')" :required="editing.id === null || !!form.password" />
            </div>
            <p class="my-3 text-xs text-slate-600">{{ t('userPasswordHelp') }}</p>
            <div class="flex gap-3"><UiButton type="submit" :loading="saving">{{ t('userSave') }}</UiButton><UiButton variant="secondary" :disabled="saving" @click="editing = null">{{ t('uiCancel') }}</UiButton></div>
        </form>
        <p class="text-sm text-slate-600">{{ t('userMembershipHelp') }}</p>
    </section>
</template>
