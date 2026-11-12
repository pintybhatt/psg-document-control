<script setup>
import { computed, ref, watch } from 'vue';

// Profile photo when a URL is supplied; otherwise the user's initials.
const props = defineProps({
    name: { type: String, default: '' },
    photoUrl: { type: String, default: '' },
    size: { type: String, default: 'md', validator: value => ['md', 'lg', 'xl'].includes(value) },
});
const photoFailed = ref(false);
watch(() => props.photoUrl, () => { photoFailed.value = false; });
const initials = computed(() => props.name.trim().split(/\s+/).filter(Boolean).slice(0, 2).map(part => [...part][0]).join('').toUpperCase() || '?');
const sizes = { md: 'size-9 text-sm', lg: 'size-12 text-base', xl: 'size-28 text-3xl' };
</script>

<template>
    <span class="relative inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full bg-gradient-to-br from-teal-500 to-sky-600 font-semibold text-white ring-2 ring-white shadow-sm" :class="sizes[size]" aria-hidden="true" data-avatar>
        <img v-if="photoUrl && !photoFailed" :src="photoUrl" alt="" class="size-full object-cover" @error="photoFailed = true" />
        <template v-else>{{ initials }}</template>
    </span>
</template>
