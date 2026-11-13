<script setup>
import { nextTick, ref } from 'vue';
import { useLocalization } from '../../localization/index.js';

defineProps({ items: { type: Array, default: () => [] } });
const emit = defineEmits(['dismiss']);
const { t } = useLocalization();
const region = ref(null);
const styles = {
    success: 'border-emerald-300 bg-emerald-50 text-emerald-900',
    warning: 'border-amber-300 bg-amber-50 text-amber-900',
    error: 'border-red-300 bg-red-50 text-red-900',
    info: 'border-blue-300 bg-blue-50 text-blue-900',
};

async function dismiss(id, event) {
    const button = event.currentTarget;
    const shouldRestoreFocus = document.activeElement === button;
    const buttons = [...region.value.querySelectorAll('button')];
    const nextButton = buttons[buttons.indexOf(button) + 1] ?? buttons[buttons.indexOf(button) - 1];
    emit('dismiss', id);
    await nextTick();

    if (shouldRestoreFocus) {
        (nextButton?.isConnected ? nextButton : region.value)?.focus();
    }
}
</script>

<template>
    <section ref="region" :aria-label="t('uiNotifications')" tabindex="-1" class="fixed right-4 bottom-4 z-40 flex max-h-[60dvh] w-[calc(100%_-_2rem)] max-w-sm flex-col gap-3 overflow-y-auto rounded-lg">
        <article v-for="item in items" :key="item.id" :role="item.type === 'error' || item.type === 'warning' ? 'alert' : 'status'" aria-atomic="true" class="flex items-start gap-4 rounded-xl border p-4 shadow-lg" :class="styles[item.type] ?? styles.info">
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold">{{ t(`uiToast-${Object.hasOwn(styles, item.type) ? item.type : 'info'}`) }}</p>
                <p class="mt-1 text-sm leading-6 wrap-break-word">{{ item.messageKey ? t(item.messageKey, item.params) : item.message }}</p>
            </div>
            <button type="button" :aria-label="t('uiDismissNotification')" :title="t('uiDismissNotification')" class="rounded px-2 py-1 text-lg leading-none hover:bg-white/50" @click="dismiss(item.id, $event)"><span aria-hidden="true">×</span></button>
        </article>
    </section>
</template>
