<script setup>
import { ClipLoader } from 'vue-spinner';
import 'vue-spinner/style.css';
import { busyCount } from '../../utilities/busy.js';
import { useLocalization } from '../../localization/index.js';

const { t } = useLocalization();
</script>

<template>
    <Teleport to="body">
        <div v-if="busyCount > 0" data-busy-overlay aria-busy="true" class="fixed inset-0 z-[1100] grid place-items-center bg-slate-950/30 p-4">
            <div class="flex flex-col items-center gap-4 rounded-2xl bg-white px-10 py-7 shadow-2xl ring-1 ring-slate-900/5">
                <ClipLoader color="#0f766e" size="48px" />
                <p class="text-sm font-medium text-slate-700" aria-live="assertive">{{ t('uiSaving') }}</p>
            </div>
        </div>
    </Teleport>
</template>
