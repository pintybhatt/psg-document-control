<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import { Icon } from '@iconify/vue';
import cameraIcon from '@iconify-icons/flowbite/camera-photo-solid';
import uploadIcon from '@iconify-icons/flowbite/upload-outline';
import trashIcon from '@iconify-icons/flowbite/trash-bin-outline';
import envelopeIcon from '@iconify-icons/flowbite/envelope-outline';
import shieldIcon from '@iconify-icons/flowbite/shield-check-solid';
import shieldOutlineIcon from '@iconify-icons/flowbite/shield-check-outline';
import briefcaseIcon from '@iconify-icons/flowbite/briefcase-outline';
import usersIcon from '@iconify-icons/flowbite/users-group-outline';
import buildingIcon from '@iconify-icons/flowbite/building-outline';
import checkIcon from '@iconify-icons/flowbite/check-circle-solid';
import imageIcon from '@iconify-icons/flowbite/image-outline';
import UserAvatar from '../components/ui/UserAvatar.vue';
import SweetConfirmDialog from '../components/ui/SweetConfirmDialog.vue';
import ProfilePasswordCard from '../components/ProfilePasswordCard.vue';
import { showSuccess } from '../utilities/alerts.js';
import { createJsonClient } from '../utilities/jsonClient.js';
import { useLocalization } from '../localization/index.js';

const props = defineProps({
    user: { type: Object, required: true },
    profile: { type: Object, required: true },
    photoEndpoint: { type: String, default: '' },
    passwordEndpoint: { type: String, default: '' },
    csrfToken: { type: String, default: '' },
});
const emit = defineEmits(['photo-changed']);
const { t, language } = useLocalization();
const request = createJsonClient(props.csrfToken);

const MAX_BYTES = 2 * 1024 * 1024;
const ACCEPTED = ['image/jpeg', 'image/png', 'image/webp'];
const fileInput = ref(null);
const dragDepth = ref(0);
const dragging = computed(() => dragDepth.value > 0);
const uploading = ref(false);
const removing = ref(false);
const confirmRemove = ref(false);
const error = ref('');
const localPreview = ref('');
const photoUrl = computed(() => localPreview.value || props.user.photoUrl || '');
const canEditPhoto = computed(() => !!props.photoEndpoint);
let lightbox = null;

const label = item => item ? (language.value === 'lo' ? item.name_lo : item.name_en) || item.name_en : '';
const scopeLabel = scope => scope.type === 'global' ? t('profileScopeGlobal') : [label(scope.company), label(scope.site)].filter(Boolean).join(' / ');
const membershipLabel = membership => [label(membership.company), label(membership.site), label(membership.department)].filter(Boolean).join(' / ');

const permissionGroups = computed(() => {
    const groups = new Map();
    for (const permission of props.profile.permissions) {
        if (!groups.has(permission.area)) { groups.set(permission.area, []); }
        groups.get(permission.area).push(permission);
    }
    return [...groups].map(([area, items]) => ({ area, items }));
});

const stats = computed(() => [
    { key: 'roles', icon: briefcaseIcon, value: props.profile.roles.length, label: t('profileRoles'), tone: 'bg-teal-50 text-teal-700 ring-teal-100' },
    { key: 'memberships', icon: usersIcon, value: props.profile.memberships.length, label: t('profileMemberships'), tone: 'bg-sky-50 text-sky-700 ring-sky-100' },
    { key: 'permissions', icon: shieldOutlineIcon, value: props.profile.permissions.length, label: t('profileStatPermissions'), tone: 'bg-emerald-50 text-emerald-700 ring-emerald-100' },
]);

function choosePhoto() {
    if (!uploading.value) { fileInput.value?.click(); }
}

function clearPreview() {
    if (localPreview.value) { URL.revokeObjectURL(localPreview.value); }
    localPreview.value = '';
}

async function upload(file) {
    error.value = '';
    if (!file) { return; }
    if (!ACCEPTED.includes(file.type)) { error.value = t('profilePhotoInvalidType'); return; }
    if (file.size > MAX_BYTES) { error.value = t('profilePhotoTooLarge'); return; }
    clearPreview();
    localPreview.value = URL.createObjectURL(file);
    uploading.value = true;
    const body = new FormData();
    body.append('photo', file);
    try {
        const data = await request(props.photoEndpoint, { method: 'POST', body });
        emit('photo-changed', data.photoUrl);
        showSuccess(t('profilePhotoUpdated'));
    } catch (failure) {
        error.value = failure.message === 'rbacSessionExpired' ? t('rbacSessionExpired') : t('profilePhotoFailed');
    } finally {
        uploading.value = false;
        clearPreview();
        if (fileInput.value) { fileInput.value.value = ''; }
    }
}

function onDragEnter() {
    if (canEditPhoto.value) { dragDepth.value += 1; }
}

function onDragLeave() {
    dragDepth.value = Math.max(0, dragDepth.value - 1);
}

function onDrop(event) {
    dragDepth.value = 0;
    if (canEditPhoto.value && !uploading.value) { upload(event.dataTransfer?.files?.[0]); }
}

async function removePhoto() {
    error.value = '';
    removing.value = true;
    try {
        await request(props.photoEndpoint, { method: 'DELETE' });
        emit('photo-changed', null);
        showSuccess(t('profilePhotoRemoved'));
    } catch {
        error.value = t('profilePhotoFailed');
    } finally {
        removing.value = false;
    }
}

async function openPhoto() {
    if (!photoUrl.value) { return; }
    const [{ default: GLightbox }] = await Promise.all([import('glightbox'), import('glightbox/dist/css/glightbox.min.css')]);
    lightbox?.destroy();
    lightbox = GLightbox({ elements: [{ href: photoUrl.value, type: 'image', title: props.user.name }], loop: false, touchNavigation: true });
    lightbox.open();
}

onBeforeUnmount(() => {
    lightbox?.destroy();
    clearPreview();
});
</script>

<template>
    <div data-profile-page class="flex flex-col gap-6">
        <!-- Identity card: whole card accepts a dropped photo. -->
        <section data-profile-account data-profile-photo-drop class="relative overflow-hidden rounded-3xl border border-slate-200/80 bg-white shadow-sm shadow-slate-900/5" @dragenter.prevent="onDragEnter" @dragover.prevent @dragleave.prevent="onDragLeave" @drop.prevent="onDrop">
            <div aria-hidden="true" class="relative h-28 overflow-hidden bg-linear-to-r from-teal-600 via-cyan-600 to-sky-700 sm:h-36">
                <div class="absolute -top-16 -left-10 size-56 rounded-full bg-white/15 blur-2xl"></div>
                <div class="absolute -right-10 -bottom-20 size-64 rounded-full bg-emerald-300/25 blur-3xl"></div>
                <div class="absolute inset-0 bg-[radial-gradient(circle,rgb(255_255_255/0.18)_1px,transparent_1px)] bg-size-[18px_18px]"></div>
            </div>

            <div class="px-6 pb-6 sm:px-8 sm:pb-8">
                <div class="flex flex-col gap-4 sm:flex-row sm:gap-6">
                    <div class="relative -mt-14 shrink-0 self-center sm:-mt-16 sm:self-start">
                        <div class="rounded-full bg-white p-1.5 shadow-lg shadow-slate-900/10">
                            <button v-if="photoUrl" type="button" data-profile-photo-open :aria-label="t('profilePhotoOpen')" :title="t('profilePhotoOpen')" class="block rounded-full transition hover:brightness-95 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-600" @click="openPhoto">
                                <UserAvatar :name="user.name" :photo-url="photoUrl" size="xl" />
                            </button>
                            <UserAvatar v-else :name="user.name" size="xl" />
                        </div>
                        <span v-if="uploading" aria-hidden="true" class="absolute inset-1.5 flex items-center justify-center rounded-full bg-slate-900/40">
                            <span class="size-8 animate-spin rounded-full border-3 border-white border-r-transparent motion-reduce:animate-none"></span>
                        </span>
                        <button v-if="canEditPhoto" type="button" data-profile-photo-change :aria-label="t('profilePhotoChange')" :title="t('profilePhotoChange')" :disabled="uploading" class="absolute right-1 bottom-1 inline-flex size-9 items-center justify-center rounded-full bg-teal-700 text-white shadow-md ring-3 ring-white transition hover:bg-teal-800 disabled:opacity-60" @click="choosePhoto">
                            <Icon :icon="cameraIcon" aria-hidden="true" class="size-4" />
                        </button>
                    </div>

                    <div class="min-w-0 flex-1 text-center sm:pt-4 sm:text-left">
                        <h2 class="text-2xl font-semibold tracking-tight text-slate-900 wrap-anywhere">{{ user.name }}</h2>
                        <p class="mt-1 inline-flex max-w-full items-center gap-1.5 text-sm text-slate-500"><Icon :icon="envelopeIcon" aria-hidden="true" class="size-4 shrink-0" /><span class="wrap-anywhere">{{ user.email }}</span></p>
                        <div class="mt-3 flex flex-wrap justify-center gap-2 sm:justify-start">
                            <span v-if="profile.system_administrator" data-profile-system-administrator class="inline-flex items-center gap-1.5 rounded-full bg-linear-to-r from-teal-600 to-sky-600 px-3 py-1 text-xs font-semibold text-white shadow-sm shadow-teal-700/20"><Icon :icon="shieldIcon" aria-hidden="true" class="size-3.5" />{{ t('profileSystemAdministrator') }}</span>
                            <span v-else class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700 ring-1 ring-slate-200"><Icon :icon="shieldOutlineIcon" aria-hidden="true" class="size-3.5" />{{ t('profileStandardAccess') }}</span>
                        </div>
                    </div>

                    <div v-if="canEditPhoto" class="flex shrink-0 flex-wrap items-start justify-center gap-2 sm:pt-4">
                        <button type="button" data-profile-photo-upload :disabled="uploading" class="inline-flex min-h-10 items-center gap-2 rounded-xl border border-teal-700 bg-teal-700 px-4 text-sm font-semibold text-white shadow-sm shadow-teal-700/20 transition hover:bg-teal-800 disabled:opacity-60" @click="choosePhoto">
                            <Icon :icon="uploadIcon" aria-hidden="true" class="size-4" />{{ uploading ? t('profilePhotoUploading') : t('profilePhotoUpload') }}
                        </button>
                        <button v-if="user.photoUrl" type="button" data-profile-photo-remove :disabled="uploading || removing" class="inline-flex min-h-10 items-center gap-2 rounded-xl border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:border-red-300 hover:bg-red-50 hover:text-red-700 disabled:opacity-60" @click="confirmRemove = true">
                            <Icon :icon="trashIcon" aria-hidden="true" class="size-4" />{{ t('profilePhotoRemove') }}
                        </button>
                    </div>
                </div>

                <input v-if="canEditPhoto" ref="fileInput" type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" tabindex="-1" :aria-label="t('profilePhoto')" @change="upload($event.target.files?.[0])" />
                <p v-if="canEditPhoto" class="mt-4 flex items-center justify-center gap-2 text-xs text-slate-500 sm:justify-start"><Icon :icon="imageIcon" aria-hidden="true" class="size-4 text-slate-400" />{{ t('profilePhotoHint') }} · {{ t('profilePhotoRules') }}</p>
                <p v-if="error" role="alert" data-profile-photo-error class="mt-3 rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-800">{{ error }}</p>

                <dl class="mt-6 grid grid-cols-3 gap-3">
                    <div v-for="stat in stats" :key="stat.key" :data-profile-stat="stat.key" class="flex flex-col items-center gap-2 rounded-2xl border border-slate-200/80 bg-slate-50/60 p-3 text-center sm:flex-row sm:p-4 sm:text-left">
                        <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-xl ring-1" :class="stat.tone"><Icon :icon="stat.icon" aria-hidden="true" class="size-5" /></span>
                        <div class="flex min-w-0 flex-col-reverse">
                            <dt class="text-xs text-slate-500">{{ stat.label }}</dt>
                            <dd class="text-xl font-semibold text-slate-900">{{ stat.value }}</dd>
                        </div>
                    </div>
                </dl>
            </div>

            <div v-if="dragging" aria-hidden="true" class="pointer-events-none absolute inset-2 z-10 flex flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-teal-500 bg-teal-50/90 text-teal-800 backdrop-blur-sm">
                <Icon :icon="uploadIcon" class="size-8" />
                <p class="text-sm font-semibold">{{ t('profilePhotoDropHere') }}</p>
                <p class="text-xs">{{ t('profilePhotoRules') }}</p>
            </div>
        </section>

        <ProfilePasswordCard v-if="passwordEndpoint && csrfToken" :endpoint="passwordEndpoint" :csrf-token="csrfToken" />

        <!-- Access card: roles and memberships beside the granted permissions. -->
        <section data-profile-access class="overflow-hidden rounded-3xl border border-slate-200/80 bg-white shadow-sm shadow-slate-900/5">
            <header class="flex items-center gap-3 border-b border-slate-100 px-6 py-5 sm:px-8">
                <span aria-hidden="true" class="inline-flex size-10 items-center justify-center rounded-xl bg-linear-to-br from-teal-500 to-sky-600 text-white shadow-md shadow-teal-700/20"><Icon :icon="shieldIcon" class="size-5" /></span>
                <div class="min-w-0">
                    <h2 class="text-base font-semibold text-slate-900">{{ t('profileAccessTitle') }}</h2>
                    <p class="text-sm text-slate-500">{{ t('profilePermissionsHelp') }}</p>
                </div>
            </header>

            <div class="grid lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
                <aside class="flex flex-col gap-6 border-b border-slate-100 bg-slate-50/50 p-6 sm:px-8 lg:border-r lg:border-b-0">
                    <div data-profile-roles>
                        <h3 class="text-xs font-semibold tracking-wider text-slate-500 uppercase">{{ t('profileRoles') }}</h3>
                        <ul v-if="profile.roles.length" class="mt-3 flex flex-col gap-2">
                            <li v-for="(role, index) in profile.roles" :key="`${role.code}-${index}`" data-profile-role class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-white p-3 shadow-xs">
                                <span aria-hidden="true" class="inline-flex size-9 shrink-0 items-center justify-center rounded-xl bg-teal-50 text-teal-700 ring-1 ring-teal-100"><Icon :icon="briefcaseIcon" class="size-4.5" /></span>
                                <div class="min-w-0">
                                    <p class="font-semibold text-slate-800 wrap-anywhere">{{ label(role) }}</p>
                                    <p class="mt-1 inline-flex max-w-full items-center gap-1 rounded-md bg-slate-100 px-2 py-0.5 text-xs text-slate-600"><Icon :icon="buildingIcon" aria-hidden="true" class="size-3.5 shrink-0" /><span class="wrap-anywhere">{{ t('profileRoleScope') }}: {{ scopeLabel(role.scope) }}</span></p>
                                </div>
                            </li>
                        </ul>
                        <p v-else class="mt-3 rounded-xl border border-dashed border-slate-300 px-4 py-3 text-sm text-slate-500">{{ t('profileNoRoles') }}</p>
                    </div>

                    <div data-profile-memberships>
                        <h3 class="text-xs font-semibold tracking-wider text-slate-500 uppercase">{{ t('profileMemberships') }}</h3>
                        <ul v-if="profile.memberships.length" class="mt-3 flex flex-col gap-2">
                            <li v-for="(membership, index) in profile.memberships" :key="index" data-profile-membership class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-white p-3 shadow-xs">
                                <span aria-hidden="true" class="inline-flex size-9 shrink-0 items-center justify-center rounded-xl bg-sky-50 text-sky-700 ring-1 ring-sky-100"><Icon :icon="usersIcon" class="size-4.5" /></span>
                                <div class="min-w-0">
                                    <span class="text-xs font-semibold text-sky-700">{{ t(`membershipType-${membership.type}`) }}</span>
                                    <p class="text-sm text-slate-700 wrap-anywhere">{{ membershipLabel(membership) }}</p>
                                </div>
                            </li>
                        </ul>
                        <p v-else class="mt-3 rounded-xl border border-dashed border-slate-300 px-4 py-3 text-sm text-slate-500">{{ t('profileNoMemberships') }}</p>
                    </div>
                </aside>

                <div data-profile-permissions class="p-6 sm:px-8">
                    <h3 class="text-xs font-semibold tracking-wider text-slate-500 uppercase">{{ t('profilePermissions', { count: profile.permissions.length }) }}</h3>
                    <div v-if="permissionGroups.length" class="mt-3 grid gap-3 md:grid-cols-2">
                        <div v-for="group in permissionGroups" :key="group.area" data-profile-permission-group class="rounded-2xl border border-slate-200 p-4 transition-colors hover:border-teal-200 hover:bg-teal-50/30">
                            <div class="flex items-center justify-between gap-2">
                                <p class="text-sm font-semibold text-slate-800">{{ t(`rbacArea-${group.area}`) }}</p>
                                <span class="rounded-full bg-teal-50 px-2 py-0.5 text-xs font-semibold text-teal-700 ring-1 ring-teal-100">{{ group.items.length }}</span>
                            </div>
                            <ul class="mt-3 flex flex-col gap-1.5">
                                <li v-for="permission in group.items" :key="permission.key" class="flex items-start gap-2 text-sm text-slate-600">
                                    <Icon :icon="checkIcon" aria-hidden="true" class="mt-0.5 size-4 shrink-0 text-emerald-500" />
                                    <span>{{ label(permission) }}</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                    <p v-else class="mt-3 rounded-xl border border-dashed border-slate-300 px-4 py-3 text-sm text-slate-500">{{ t('profileNoPermissions') }}</p>
                </div>
            </div>
        </section>

        <SweetConfirmDialog v-model="confirmRemove" danger :title="t('profilePhotoRemove')" :message="t('profilePhotoRemoveConfirm')" :confirm-label="t('profilePhotoRemove')" @confirm="removePhoto" />
    </div>
</template>
