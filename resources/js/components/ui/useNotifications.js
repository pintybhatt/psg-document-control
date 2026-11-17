import { onScopeDispose, readonly, ref } from 'vue';
import { useLocalization } from '../../localization/index.js';
import { showSuccess } from '../../utilities/alerts.js';

export function useNotifications() {
    const items = ref([]);
    let nextId = 0;
    const timers = new Map();
    const localization = useLocalization();

    function notify(notification) {
        const id = ++nextId;
        // Successful saves are confirmed with a SweetAlert2 popup instead of a toast.
        if (notification.type === 'success' && localization) {
            const { t } = localization;
            showSuccess(t('uiSaveSuccess'), notification.messageKey ? t(notification.messageKey, notification.params ?? {}) : (notification.message ?? ''));
            return id;
        }
        items.value.push({ ...notification, id });
        const duration = { success: 4000, info: 5000 }[notification.type];
        if (duration) {
            timers.set(id, setTimeout(() => dismiss(id), duration));
        }

        return id;
    }

    function dismiss(id) {
        clearTimeout(timers.get(id));
        timers.delete(id);
        items.value = items.value.filter(item => item.id !== id);
    }

    onScopeDispose(() => {
        for (const timer of timers.values()) { clearTimeout(timer); }
        timers.clear();
    });

    return { items: readonly(items), notify, dismiss };
}
