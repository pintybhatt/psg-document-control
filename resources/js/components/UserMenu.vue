<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { Icon } from '@iconify/vue';
import chevronDownIcon from '@iconify-icons/flowbite/chevron-down-outline';
import UserAvatar from './ui/UserAvatar.vue';
import LogoutButton from './LogoutButton.vue';
import { useLocalization } from '../localization/index.js';

const props = defineProps({ auth: { type: Object, required: true } });
const { t } = useLocalization();
const open = ref(false);
const root = ref(null);
const trigger = ref(null);
const panel = ref(null);
const name = computed(() => props.auth.user?.name ?? '');
const email = computed(() => props.auth.user?.email ?? '');
const photoUrl = computed(() => props.auth.user?.photoUrl ?? '');

async function toggle() {
    open.value = !open.value;
    if (open.value) {
        await nextTick();
        panel.value?.querySelector('a, button')?.focus();
    }
}

function close(returnFocus = false) {
    if (!open.value) { return; }
    open.value = false;
    if (returnFocus) { trigger.value?.focus(); }
}

function handleDocumentClick(event) {
    if (!root.value?.contains(event.target)) { close(); }
}

onMounted(() => document.addEventListener('click', handleDocumentClick));
onBeforeUnmount(() => document.removeEventListener('click', handleDocumentClick));
</script>

<template>
    <div ref="root" class="relative" @keydown.esc.stop="close(true)">
        <button ref="trigger" type="button" data-user-menu-trigger aria-haspopup="true" :aria-expanded="open" aria-controls="user-menu" :aria-label="t('userMenu')" class="flex items-center gap-3 rounded-full py-1 pr-2 pl-1 transition-colors hover:bg-slate-100" :class="{ 'bg-slate-100': open }" @click="toggle">
            <UserAvatar :name="name" :photo-url="photoUrl" />
            <span class="hidden min-w-0 text-left md:block">
                <span class="block max-w-44 truncate text-sm font-semibold text-slate-800">{{ name }}</span>
                <span class="block max-w-44 truncate text-xs text-slate-500">{{ email }}</span>
            </span>
            <Icon :icon="chevronDownIcon" aria-hidden="true" class="hidden size-4 text-slate-400 transition-transform md:block" :class="{ 'rotate-180': open }" />
        </button>
        <div v-show="open" id="user-menu" ref="panel" class="absolute right-0 z-40 mt-2 w-72 origin-top-right overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl ring-1 ring-slate-900/5">
            <component :is="auth.profileUrl ? 'a' : 'div'" :href="auth.profileUrl" data-user-profile-link :title="auth.profileUrl ? t('profileView') : undefined" class="group flex items-center gap-3 border-b border-slate-100 bg-slate-50 px-4 py-4" :class="{ 'transition-colors hover:bg-teal-50': auth.profileUrl }">
                <UserAvatar :name="name" :photo-url="photoUrl" size="lg" />
                <div class="min-w-0 flex-1">
                    <p class="text-xs font-medium text-teal-700">{{ t('userSignedInAs') }}</p>
                    <p class="truncate text-sm font-semibold text-slate-900 group-hover:text-teal-800">{{ name }}</p>
                    <p class="truncate text-xs text-slate-500">{{ email }}</p>
                    <p v-if="auth.profileUrl" class="mt-1 text-xs font-medium text-teal-700 group-hover:underline">{{ t('profileView') }}</p>
                </div>
            </component>
            <div class="p-2"><LogoutButton :auth="auth" menu /></div>
        </div>
    </div>
</template>
